<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\Notifications\RecordingNoticeChannel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * What the worker does with a notice when the mail provider is down, with the real queue of the database and a channel that always fails (RF-34, RNF-09, #135):
 * the reservation stays confirmed, the task is attempted 3 times and then stays in `failed_jobs` for the monitoring.
 */
final class NoticeRetriesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private RecordingNoticeChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['queue.default' => 'database', 'notices.mail_per_second' => 100]);
        $this->channel = new RecordingNoticeChannel;
        $this->channel->failing = true;
        $this->app->instance(NoticeChannel::class, $this->channel);

        $this->seedBookableBarbershops();
        $this->app->instance(Clock::class, new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'))));
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** Run what is available in the queue, as `queue:work` does, until it is empty. */
    private function work(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--quiet' => true]));
    }

    /** The email fails every time: the reservation is not undone, the task runs 3 times with the wait of the backoff in between, and then it is recorded as failed. */
    public function test_a_failing_provider_does_not_undo_the_reservation_and_the_task_is_attempted_three_times(): void
    {
        [$ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->actAs($ana);
        $id = (string) $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->count(), 'The confirmation waits in the queue.');

        $this->work();

        $this->assertSame(1, $this->channel->attempts);
        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->count(), 'After the first failure it goes back to the queue.');
        $this->assertGreaterThan(time() + 5, (int) DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->value('available_at'), 'It waits before the second attempt.');
        $this->assertSame(0, DB::table('failed_jobs')->count());

        foreach ([2, 3] as $attempt) {
            DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->update(['available_at' => 0]);
            $this->work();
            $this->assertSame($attempt, $this->channel->attempts);
        }

        $this->assertSame(3, $this->channel->attempts, 'Exactly 3 attempts.');
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'After the third failure it stays visible for the monitoring.');
        $this->assertStringContainsString('SendNoticeJob', (string) DB::table('failed_jobs')->value('payload'));
        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->count());
        $this->assertSame('confirmed', DB::table('reservations')->where('id', $id)->value('status'), 'The reservation is not undone.');
        $this->assertSame(2, DB::table('reservation_reminders')->where('reservation_id', $id)->count(), 'The reminders were left all the same.');
    }

    /** Over the limit per second the task is not a failure: it goes back to the queue, uses no attempt and goes out in a later second (the rate limit of Resend). */
    public function test_over_the_rate_limit_the_task_waits_instead_of_failing(): void
    {
        config(['notices.mail_per_second' => 1]);
        $this->channel->failing = false;
        [$ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->actAs($ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertCreated();

        $this->work();

        $this->assertCount(1, $this->channel->sent, 'Only one email in the second.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->count(), 'The other one waits in the queue.');

        sleep(2);
        DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->update(['available_at' => 0]);
        $this->work();

        $this->assertCount(2, $this->channel->sent);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /** When the provider comes back the retry sends the notice once. */
    public function test_the_retry_sends_when_the_provider_recovers(): void
    {
        [$ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->actAs($ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        $this->work();
        $this->channel->failing = false;
        DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->update(['available_at' => 0]);
        $this->work();

        $this->assertCount(1, $this->channel->sent);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%SendNoticeJob%')->count());
    }
}
