<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\AssistedClients;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Infrastructure\Jobs\SendNoticeJob;
use Src\Notifications\Notices\Infrastructure\Jobs\SendReminderJob;
use Src\Notifications\Notices\Infrastructure\Mail\NoticeMail;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * What an assisted client hears about their reservation (RF-12, CL-06, RF-33, RF-34, NO-10): without an email nothing is sent and no reminder is
 * left; with one, the confirmation and the two reminders go out as for any customer, with the reminders on by default, and the cancellation or the
 * move that the owner makes is told. The queue and the mail are faked: nothing leaves the process. Today is Friday 1 March 2030, 8:00.
 */
final class AssistedReservationNoticesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private QueueFake $queue;

    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->queue = Queue::fake([SendNoticeJob::class, SendReminderJob::class]);
        Mail::fake();
        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** Register as the owner for a person with the email given, or without one. */
    private function register(?string $email, string $startsAt = self::TUESDAY_TEN): string
    {
        $this->actAs($this->owner);

        return (string) $this->postJson($this->reservationsUrl($this->puraNavaja).'/asistida', [
            ...$this->booking(startsAt: $startsAt),
            'cliente_nombre' => 'Rosa Campos',
            'cliente_telefono' => '8744 0021',
            'cliente_correo' => $email,
        ])->assertCreated()->json('data.id');
    }

    /**
     * The tasks of a class that were left in the queue, in order.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    private function pushed(string $class): array
    {
        return array_values($this->queue->pushed($class)->all());
    }

    /** Without an email no confirmation is sent and no reminder is left (CL-06, NO-02). */
    public function test_a_client_without_email_receives_nothing(): void
    {
        $this->register(null);

        foreach ($this->pushed(SendNoticeJob::class) as $job) {
            $this->app->call([$job, 'handle']);
        }

        Mail::assertNothingSent();
        $this->assertSame([], $this->pushed(SendReminderJob::class));
        $this->assertSame(0, DB::table('reservation_reminders')->count());
    }

    /** With an email the confirmation reaches that address with the service, the barber and the hour (NO-01, RF-34). */
    public function test_a_client_with_email_receives_the_confirmation(): void
    {
        $this->register('rosa@correo.cr');

        $jobs = $this->pushed(SendNoticeJob::class);
        $this->assertSame([NoticeType::Confirmation], array_map(fn (SendNoticeJob $job): NoticeType => $job->type, $jobs));
        $this->assertNull($jobs[0]->customerMembershipId);

        $this->app->call([$jobs[0], 'handle']);

        Mail::assertSent(NoticeMail::class, 1);
        Mail::assertSent(NoticeMail::class, function (NoticeMail $mail): bool {
            $this->assertTrue($mail->hasTo('rosa@correo.cr'));
            $mail->assertSeeInText('Degradado');
            $mail->assertSeeInText('Daniela');

            return true;
        });
    }

    /** With an email the two reminders are left at their hours, on by default, and the one of 24 hours is sent (RF-33, NO-02, NO-10). */
    public function test_a_client_with_email_gets_the_two_reminders_on_by_default(): void
    {
        $id = $this->register('rosa@correo.cr');

        $reminders = $this->pushed(SendReminderJob::class);
        $this->assertCount(2, $reminders);
        $this->assertEquals(new DateTimeImmutable('2030-03-04 10:00', new DateTimeZone('America/Costa_Rica')), $reminders[0]->delay);
        $this->assertEquals(new DateTimeImmutable('2030-03-05 08:00', new DateTimeZone('America/Costa_Rica')), $reminders[1]->delay);

        $this->clock->moveTo(new DateTimeImmutable('2030-03-04 10:00:05', new DateTimeZone('America/Costa_Rica')));
        $this->app->call([$reminders[0], 'handle']);

        Mail::assertSent(NoticeMail::class, fn (NoticeMail $mail): bool => $mail->hasTo('rosa@correo.cr'));
        $this->assertSame($id, DB::table('reservation_reminders')->where('kind', '24h')->value('reservation_id'));
    }

    /** The cancellation the owner makes is told to the client with an email, once, and not to one without it (NO-07). */
    public function test_the_cancellation_by_the_owner_is_told_only_to_who_has_an_email(): void
    {
        $withEmail = $this->register('rosa@correo.cr');
        $withoutEmail = $this->register(null, '2030-03-05T14:00:00-06:00');
        $this->actAs($this->owner);

        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$withEmail}/cancelacion")->assertOk();
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$withoutEmail}/cancelacion")->assertOk();

        $cancellations = array_values(array_filter($this->pushed(SendNoticeJob::class), fn (SendNoticeJob $job): bool => $job->type === NoticeType::Cancellation));
        $this->assertCount(2, $cancellations);

        foreach ($cancellations as $job) {
            $this->app->call([$job, 'handle']);
        }

        Mail::assertSent(NoticeMail::class, fn (NoticeMail $mail): bool => $mail->hasTo('rosa@correo.cr') && str_contains((string) $mail->envelope()->subject, 'cancel'));
        Mail::assertSent(NoticeMail::class, 1);
    }

    /** Moving an assisted reservation moves its reminders and tells the client with an email (RF-15, NO-07). */
    public function test_rescheduling_moves_the_reminders_and_tells_the_client(): void
    {
        $id = $this->register('rosa@correo.cr');
        $this->actAs($this->owner);

        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T14:00:00-06:00'])->assertOk();

        $types = array_map(fn (SendNoticeJob $job): NoticeType => $job->type, $this->pushed(SendNoticeJob::class));
        $this->assertContains(NoticeType::Reschedule, $types);
        $this->assertSame(1, DB::table('reservation_reminders')->where('reservation_id', $id)->where('kind', '24h')->where('scheduled_for', '2030-03-04 14:00:00-06')->count());
    }
}
