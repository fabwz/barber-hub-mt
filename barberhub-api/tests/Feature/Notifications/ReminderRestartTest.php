<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\Notifications\RecordingNoticeChannel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * What happens to the reminders when the workers stop, a task is repeated or the provider fails, with the real queue of the database and a recording
 * channel (RF-33, NO-03, RNF-10, #135): a reminder is not lost and is not sent twice. Nothing reaches a provider.
 * Today is Friday 1 March 2030, 8:00; Ana books Tuesday 5 March at 10:00, so her reminders are Monday at 10:00 and Tuesday at 8:00.
 */
final class ReminderRestartTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private RecordingNoticeChannel $channel;

    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['queue.default' => 'database', 'notices.mail_per_second' => 100]);
        $this->channel = new RecordingNoticeChannel;
        $this->app->instance(NoticeChannel::class, $this->channel);

        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(self::cr('2030-03-01 08:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->scopeQueriesTo($this->puraNavaja);
    }

    private static function cr(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /** Ana books Tuesday at 10:00; the worker runs the listener that leaves the reminders, and only their tasks stay in the queue (the confirmation is not the subject here). */
    private function bookAsAna(): string
    {
        [$ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->actAs($ana);
        $id = (string) $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
        DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->delete();
        $this->work();
        DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->delete();

        return $id;
    }

    /** Run what is available in the queue, as one `queue:work` process does until it is empty; the memory limit is raised because the process of the whole suite is already large. */
    private function work(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 2048, '--quiet' => true]));
    }

    /** How many tasks of reminders wait in the queue. */
    private function waiting(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%SendReminderJob%')->count();
    }

    /** The queue does not wait for the clock of the test: the task whose instant arrives becomes available, the oldest first. */
    private function arrive(string $moment): void
    {
        $this->clock->moveTo(self::cr($moment));
        DB::table('jobs')->where('id', DB::table('jobs')->where('payload', 'like', '%SendReminderJob%')->min('id'))->update(['available_at' => 0]);
    }

    private function reminderSentAt(string $reservation, string $kind): mixed
    {
        return DB::table('reservation_reminders')->where('reservation_id', $reservation)->where('kind', $kind)->value('sent_at');
    }

    /** @return list<NoticeType> */
    private function sentTypes(): array
    {
        return array_map(static fn ($notice): NoticeType => $notice->type, $this->channel->sent);
    }

    /** The tasks wait for their hour in the table, and each worker that starts finds them there: a restart loses none and sends none twice (RNF-10). */
    public function test_the_reminders_survive_the_restart_of_the_workers(): void
    {
        $id = $this->bookAsAna();
        $this->assertSame(2, $this->waiting());

        $this->work();
        $this->assertSame([], $this->channel->sent, 'Before their hour the workers leave them alone.');
        $this->assertSame(2, $this->waiting());

        $this->arrive('2030-03-04 10:00');
        $this->work();
        $this->assertSame([NoticeType::Reminder], $this->sentTypes());
        $this->assertNotNull($this->reminderSentAt($id, '24h'));
        $this->assertNull($this->reminderSentAt($id, '2h'));
        $this->assertSame(1, $this->waiting());

        $this->work();
        $this->assertCount(1, $this->channel->sent, 'A new worker does not repeat what went out.');

        $this->arrive('2030-03-05 08:00');
        $this->work();
        $this->assertCount(2, $this->channel->sent);
        $this->assertNotNull($this->reminderSentAt($id, '2h'));
        $this->assertSame(0, $this->waiting());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /** The task is left twice, as a repeated event or a restart of the scheduler could: the second one finds the reminder sent and sends nothing (NO-03). */
    public function test_a_duplicated_task_sends_the_reminder_once(): void
    {
        $id = $this->bookAsAna();
        $copy = (array) DB::table('jobs')->where('payload', 'like', '%SendReminderJob%')->orderBy('id')->first();
        unset($copy['id']);
        DB::table('jobs')->insert($copy);
        $this->assertSame(3, $this->waiting());

        $this->clock->moveTo(self::cr('2030-03-04 10:00'));
        DB::table('jobs')->where('available_at', $copy['available_at'])->update(['available_at' => 0]);
        $this->work();

        $this->assertCount(1, $this->channel->sent, 'Two tasks, one email.');
        $this->assertNotNull($this->reminderSentAt($id, '24h'));
        $this->assertSame(1, $this->waiting(), 'Only the 2 h one remains.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /** A worker that dies with the task in hand leaves it reserved: once the time of the queue passes, another worker takes it and the reminder is not lost (RNF-10). */
    public function test_a_task_left_reserved_by_a_dead_worker_is_taken_again(): void
    {
        $id = $this->bookAsAna();
        $this->arrive('2030-03-04 10:00');
        DB::table('jobs')->where('available_at', 0)->update(['reserved_at' => time() - 100000, 'attempts' => 1]);

        $this->work();

        $this->assertCount(1, $this->channel->sent);
        $this->assertNotNull($this->reminderSentAt($id, '24h'));
        $this->assertSame(1, $this->waiting());
    }

    /** The provider fails: the claim is undone with the transaction, so the reminder is not marked as sent, and the retry sends it once when the provider recovers (RNF-09, NO-03). */
    public function test_a_failed_reminder_is_retried_and_sent_once(): void
    {
        $this->clock->moveTo(self::cr('2030-03-04 18:00'));
        $id = $this->bookAsAna();
        $this->assertSame(1, $this->waiting(), 'Booked 16 h ahead: only the 2 h reminder.');
        $this->channel->failing = true;

        $this->arrive('2030-03-05 08:00');
        $this->work();

        $this->assertSame(1, $this->channel->attempts);
        $this->assertNull($this->reminderSentAt($id, '2h'), 'The failure undid the claim.');
        $this->assertSame(1, $this->waiting(), 'The task goes back to the queue.');
        $this->assertGreaterThan(time() + 5, (int) DB::table('jobs')->where('payload', 'like', '%SendReminderJob%')->value('available_at'), 'It waits before the second attempt.');
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->channel->failing = false;
        DB::table('jobs')->update(['available_at' => 0]);
        $this->work();

        $this->assertCount(1, $this->channel->sent);
        $this->assertNotNull($this->reminderSentAt($id, '2h'));
        $this->assertSame(0, $this->waiting());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /** After the 3 attempts the task stays in `failed_jobs`, the reminder is still unsent, and retrying it later sends it once (RNF-09, RNF-10). */
    public function test_a_reminder_that_exhausted_its_attempts_can_still_be_sent_once(): void
    {
        $this->clock->moveTo(self::cr('2030-03-04 18:00'));
        $id = $this->bookAsAna();
        $this->channel->failing = true;
        $this->arrive('2030-03-05 08:00');

        foreach ([1, 2, 3] as $attempt) {
            DB::table('jobs')->update(['available_at' => 0]);
            $this->work();
            $this->assertSame($attempt, $this->channel->attempts);
        }

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, $this->waiting());
        $this->assertNull($this->reminderSentAt($id, '2h'), 'Nothing was marked as sent.');

        $this->channel->failing = false;
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->work();

        $this->assertCount(1, $this->channel->sent);
        $this->assertNotNull($this->reminderSentAt($id, '2h'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
