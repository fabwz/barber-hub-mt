<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

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
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * The notices that the reservations cause, from the HTTP request to the email, with the queue and the mail faked (RF-33, RF-34, NO-01 to NO-03, NO-07, NO-10):
 * what is left in the queue, at what hour, and what the worker sends when it runs the tasks by hand. Nothing leaves the process.
 */
final class NoticesOnReservationsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private string $anaRole;

    private string $anaEmail;

    private MutableClock $clock;

    private QueueFake $queue;

    /** Ana is a customer of Pura Navaja, which charges nothing, so her booking is confirmed on the spot. Today is Friday 1 March 2030, 8:00. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->queue = Queue::fake([SendNoticeJob::class, SendReminderJob::class]);
        Mail::fake();

        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(self::cr('2030-03-01 08:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->scopeQueriesTo($this->puraNavaja);
        [$this->ana, $this->anaRole] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->anaEmail = (string) DB::table('users')->where('id', $this->ana)->value('email');
    }

    private static function cr(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /** Ana books Tuesday 5 March at 10:00 and the id of the reservation is returned. */
    private function bookAsAna(string $startsAt = self::TUESDAY_TEN): string
    {
        $this->actAs($this->ana);

        return (string) $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: $startsAt))->assertCreated()->json('data.id');
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

    /** Run a task the way a worker does, as the barbershop it carries. */
    private function work(SendNoticeJob|SendReminderJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /** @return list<NoticeType> */
    private function noticeTypes(): array
    {
        return array_map(fn (SendNoticeJob $job): NoticeType => $job->type, $this->pushed(SendNoticeJob::class));
    }

    /** Booking with no charge confirms and leaves one confirmation, which the worker sends to the customer with the barbershop and the hour (NO-01, RF-34). */
    public function test_a_confirmed_booking_sends_the_confirmation_email(): void
    {
        $this->bookAsAna();

        $this->assertSame([NoticeType::Confirmation], $this->noticeTypes());

        $this->work($this->pushed(SendNoticeJob::class)[0]);

        Mail::assertSent(NoticeMail::class, 1);
        Mail::assertSent(NoticeMail::class, function (NoticeMail $mail): bool {
            $this->assertTrue($mail->hasTo($this->anaEmail));
            $this->assertSame('Su reserva en pura-navaja está confirmada', $mail->envelope()->subject);
            $mail->assertSeeInText('Degradado');
            $mail->assertSeeInText('Daniela');
            $mail->assertSeeInText('martes 5 de marzo, 10:00 a. m.');

            return true;
        });
    }

    /** Booked on Friday at 8:00 for Tuesday at 10:00, the reminders are Monday at 10:00 and Tuesday at 8:00, one task each (RF-33, NO-02). */
    public function test_a_booking_leaves_the_two_reminders_at_their_hours(): void
    {
        $id = $this->bookAsAna();

        $reminders = $this->pushed(SendReminderJob::class);
        $this->assertCount(2, $reminders);
        $this->assertEquals(self::cr('2030-03-04 10:00'), $reminders[0]->delay);
        $this->assertEquals(self::cr('2030-03-05 08:00'), $reminders[1]->delay);
        $this->assertSame(['24h', '2h'], array_map(fn (SendReminderJob $job): string => $job->kind, $reminders));
        $this->assertSame($this->puraNavaja, $reminders[0]->barbershopId);
        $this->assertSame($id, $reminders[0]->reservationId);
        $this->assertSame(2, DB::table('reservation_reminders')->where('reservation_id', $id)->count());
    }

    /** A booking made 16 h ahead only has the 2 h reminder; one made 1 h ahead has none (NO-02). */
    public function test_a_late_booking_only_gets_the_reminders_that_still_fit(): void
    {
        $this->clock->moveTo(self::cr('2030-03-04 18:00'));
        $this->bookAsAna();
        $this->assertSame(['2h'], array_map(fn (SendReminderJob $job): string => $job->kind, $this->pushed(SendReminderJob::class)));

        $this->clock->moveTo(self::cr('2030-03-05 15:00'));
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T16:00:00-06:00'))->assertCreated();

        $this->assertCount(1, $this->pushed(SendReminderJob::class), 'The second booking has less than 2 hours of notice: no reminder.');
    }

    /** The worker sends the reminder at its hour, with the barbershop and the hour of the reservation, and a second run sends nothing (RF-33, NO-03). */
    public function test_the_reminder_goes_out_once_at_its_hour(): void
    {
        $this->bookAsAna();
        $dayBefore = $this->pushed(SendReminderJob::class)[0];
        $this->clock->moveTo(self::cr('2030-03-04 10:00'));

        $this->work($dayBefore);
        $this->work($dayBefore);

        Mail::assertSent(NoticeMail::class, 1);
        Mail::assertSent(NoticeMail::class, function (NoticeMail $mail): bool {
            $this->assertSame('Recordatorio: su reserva en pura-navaja es mañana', $mail->envelope()->subject);
            $mail->assertSeeInText('martes 5 de marzo, 10:00 a. m.');

            return true;
        });
        $this->assertNotNull(DB::table('reservation_reminders')->where('kind', '24h')->value('sent_at'));
    }

    /** A cancelled reservation sends no reminder, and the customer who cancelled gets no cancellation email (NO-03, NO-07). */
    public function test_a_cancellation_by_the_customer_sends_nothing_more(): void
    {
        $id = $this->bookAsAna();
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")->assertOk();

        $this->assertSame([NoticeType::Confirmation], $this->noticeTypes());

        $this->clock->moveTo(self::cr('2030-03-04 10:00'));
        foreach ($this->pushed(SendReminderJob::class) as $reminder) {
            $this->work($reminder);
        }

        Mail::assertNothingSent();
    }

    /** When the barbershop cancels, the customer is told, and the reminders do not go out (NO-07, NO-03). */
    public function test_a_cancellation_by_the_barbershop_tells_the_customer(): void
    {
        $id = $this->bookAsAna();
        $this->actAs($this->owner);
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")->assertOk();

        $this->assertSame([NoticeType::Confirmation, NoticeType::Cancellation], $this->noticeTypes());

        $this->work($this->pushed(SendNoticeJob::class)[1]);
        $this->clock->moveTo(self::cr('2030-03-05 08:00'));
        foreach ($this->pushed(SendReminderJob::class) as $reminder) {
            $this->work($reminder);
        }

        Mail::assertSent(NoticeMail::class, 1);
        Mail::assertSent(NoticeMail::class, function (NoticeMail $mail): bool {
            $this->assertTrue($mail->hasTo($this->anaEmail));
            $this->assertSame('La barbería canceló su reserva en pura-navaja', $mail->envelope()->subject);

            return true;
        });
    }

    /** A reschedule by the barbershop tells the customer the old and the new hour; one by the customer does not (NO-07). */
    public function test_a_reschedule_is_told_only_when_the_barbershop_makes_it(): void
    {
        $id = $this->bookAsAna();
        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T14:00:00-06:00'])->assertOk();

        $this->assertSame([NoticeType::Confirmation], $this->noticeTypes(), 'The customer who moves it already knows.');

        $this->actAs($this->owner);
        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T16:00:00-06:00'])->assertOk();

        $this->assertSame([NoticeType::Confirmation, NoticeType::Reschedule], $this->noticeTypes());
        $this->work($this->pushed(SendNoticeJob::class)[1]);

        Mail::assertSent(NoticeMail::class, function (NoticeMail $mail): bool {
            $this->assertSame('La barbería cambió la hora de su reserva en pura-navaja', $mail->envelope()->subject);
            $mail->assertSeeInText('Hora anterior: martes 5 de marzo, 2:00 p. m.');
            $mail->assertSeeInText('Hora nueva: martes 5 de marzo, 4:00 p. m.');

            return true;
        });
    }

    /**
     * The 24 h reminder already went out and the reservation is moved to another day: the reminders follow the new hour, the task of the old
     * hour does nothing, and the new 24 h reminder is sent for the new day (NO-02, NO-03).
     */
    public function test_a_reschedule_after_the_day_reminder_went_out_sends_the_new_ones(): void
    {
        $id = $this->bookAsAna();
        [$dayBefore, $twoHours] = $this->pushed(SendReminderJob::class);
        $this->clock->moveTo(self::cr('2030-03-04 10:00'));
        $this->work($dayBefore);
        Mail::assertSent(NoticeMail::class, 1);

        $this->actAs($this->owner);
        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-12T10:00:00-06:00'])->assertOk();

        $reminders = $this->pushed(SendReminderJob::class);
        $this->assertCount(4, $reminders);
        $this->assertEquals(self::cr('2030-03-11 10:00'), $reminders[2]->delay);
        $this->assertEquals(self::cr('2030-03-12 08:00'), $reminders[3]->delay);
        $this->assertNull(DB::table('reservation_reminders')->where('reservation_id', $id)->where('kind', '24h')->value('sent_at'), 'The new hour has its own unsent reminder.');

        $this->work($twoHours);
        Mail::assertSent(NoticeMail::class, 1);

        $this->clock->moveTo(self::cr('2030-03-11 10:00'));
        $this->work($reminders[2]);
        Mail::assertSent(NoticeMail::class, 2);
    }

    /** Turning the reminders off leaves none, and the confirmation still goes out because it cannot be turned off (NO-10). */
    public function test_a_customer_without_reminders_still_gets_the_confirmation(): void
    {
        DB::table('users')->where('id', $this->ana)->update(['reminders_enabled' => false]);

        $this->bookAsAna();

        $this->assertSame([], $this->pushed(SendReminderJob::class));
        $this->assertSame(0, DB::table('reservation_reminders')->count());
        $this->assertSame([NoticeType::Confirmation], $this->noticeTypes());
        $this->work($this->pushed(SendNoticeJob::class)[0]);
        Mail::assertSent(NoticeMail::class, 1);
    }

    /** A reminder that was left before the customer turned them off is not sent (NO-10). */
    public function test_turning_the_reminders_off_later_stops_the_ones_already_left(): void
    {
        $this->bookAsAna();
        DB::table('users')->where('id', $this->ana)->update(['reminders_enabled' => false]);
        $this->clock->moveTo(self::cr('2030-03-04 10:00'));

        $this->work($this->pushed(SendReminderJob::class)[0]);

        Mail::assertNothingSent();
    }

    /** A space offered to a person of the waitlist is told by email with the hour they have to answer (NO-07, LE-04). */
    public function test_an_offer_is_told_with_its_deadline(): void
    {
        $this->app->make(DomainEvents::class)->publish(new OfferCreated(
            $this->puraNavaja,
            'offer-1',
            'entry-1',
            $this->anaRole,
            $this->degradado,
            $this->daniela,
            self::cr('2030-03-05 13:00'),
            self::cr('2030-03-05 14:05'),
            self::cr('2030-03-05 11:15'),
        ));

        $this->assertSame([NoticeType::Offer], $this->noticeTypes());
        $this->work($this->pushed(SendNoticeJob::class)[0]);

        Mail::assertSent(NoticeMail::class, function (NoticeMail $mail): bool {
            $this->assertTrue($mail->hasTo($this->anaEmail));
            $mail->assertSeeInText('Puede aceptarlo hasta las 11:15 a. m.');

            return true;
        });
    }

    /** A task that carries another barbershop reaches nobody of this one: the customer role is not found there and nothing is sent (MT-04). */
    public function test_a_task_of_another_barbershop_sends_nothing(): void
    {
        $this->work(new SendNoticeJob(
            $this->fadeLab,
            NoticeType::Confirmation,
            $this->anaRole,
            $this->corte,
            $this->mauricio,
            self::cr('2030-03-05 10:00'),
            self::cr('2030-03-05 10:30'),
            null,
            null,
            'confirmation-x',
        ));

        Mail::assertNothingSent();
    }
}
