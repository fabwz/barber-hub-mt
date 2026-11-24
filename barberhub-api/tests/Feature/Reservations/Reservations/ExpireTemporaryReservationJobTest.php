<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Reservations\Reservations\Infrastructure\Jobs\ExpireTemporaryReservationJob;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * The task that closes the payment window of a temporary reservation (RE-05, RNF-09): booking leaves it in the queue for the instant the
 * window closes, carrying its barbershop, and running it expires the reservation, announces its space and never reaches another barbershop.
 */
final class ExpireTemporaryReservationJobTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private MutableClock $clock;

    /** Pura Navaja charges a deposit, so a booking stays temporary. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);
    }

    /** A temporary reservation leaves one task, for one second after its window closes, with its barbershop, that waits for the commit (RE-05). */
    public function test_booking_leaves_the_task_for_the_end_of_the_window(): void
    {
        Queue::fake();
        $this->actAs($this->ana);

        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');

        Queue::assertPushed(ExpireTemporaryReservationJob::class, 1);
        Queue::assertPushed(ExpireTemporaryReservationJob::class, function (ExpireTemporaryReservationJob $job) use ($id): bool {
            $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
            $this->assertInstanceOf(DateTimeInterface::class, $job->delay);

            return $job->reservationId === $id
                && $job->barbershopId === $this->puraNavaja
                && $job->delay->getTimestamp() === $this->clock->now()->modify('+10 minutes +1 second')->getTimestamp();
        });
    }

    /** A barbershop that does not charge confirms at once and leaves no task to expire it (RE-07); the notices of the confirmation are another matter (#96). */
    public function test_a_reservation_confirmed_at_once_leaves_no_task(): void
    {
        Queue::fake();
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'none', 'deposit_percentage' => null, 'payment_gateway' => null]);
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        Queue::assertNotPushed(ExpireTemporaryReservationJob::class);
    }

    /** Once the window closes the task expires the reservation by the system and announces its space, with no booking asking for the collaborator (RE-05, RE-09, RF-31). */
    public function test_the_task_expires_the_reservation_and_announces_its_space(): void
    {
        Event::fake([SpaceReleased::class]);
        $id = $this->book();
        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 08:10:01', new DateTimeZone('America/Costa_Rica')));

        $this->runTask($id, $this->puraNavaja);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('expired', DB::table('reservations')->where('id', $id)->value('status'));
        $this->assertSame(['temporary', 'expired'], DB::table('reservation_status_history')->where('reservation_id', $id)->orderBy('changed_at')->pluck('to_status')->all());
        Event::assertDispatchedTimes(SpaceReleased::class, 1);
        Event::assertDispatched(SpaceReleased::class, fn (SpaceReleased $event): bool => $event->reservationId === $id
            && $event->barbershopId === $this->puraNavaja
            && $event->collaboratorId === $this->daniela
            && $event->startsAt->format('Y-m-d H:i') === '2030-03-05 10:00'
            && $event->endsAt->format('Y-m-d H:i') === '2030-03-05 10:50');
    }

    /** Running the task twice, as a retry of the queue does, announces the space once (RNF-09). */
    public function test_running_the_task_twice_announces_the_space_once(): void
    {
        Event::fake([SpaceReleased::class]);
        $id = $this->book();
        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 08:11:00', new DateTimeZone('America/Costa_Rica')));

        $this->runTask($id, $this->puraNavaja);
        $this->runTask($id, $this->puraNavaja);

        Event::assertDispatchedTimes(SpaceReleased::class, 1);
    }

    /** A task that runs before the window closes changes nothing: the reservation keeps holding its space (DI-05). */
    public function test_a_task_before_the_deadline_changes_nothing(): void
    {
        Event::fake([SpaceReleased::class]);
        $id = $this->book();
        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 08:09:59', new DateTimeZone('America/Costa_Rica')));

        $this->runTask($id, $this->puraNavaja);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('temporary', DB::table('reservations')->where('id', $id)->value('status'));
        Event::assertNotDispatched(SpaceReleased::class);
    }

    /** A reservation that was confirmed by its payment before the window closed is not expired by a late task. */
    public function test_a_paid_reservation_is_not_expired(): void
    {
        Event::fake([SpaceReleased::class]);
        $id = $this->book();
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('reservations')->where('id', $id)->update(['status' => 'confirmed', 'expires_at' => null]);
        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 09:00:00', new DateTimeZone('America/Costa_Rica')));

        $this->runTask($id, $this->puraNavaja);

        $this->assertSame('confirmed', DB::table('reservations')->where('id', $id)->value('status'));
        Event::assertNotDispatched(SpaceReleased::class);
    }

    /** A task carrying another barbershop cannot reach the reservation: it finds nothing, and the reservation is left as it was (MT-02, MT-03). */
    public function test_a_task_of_another_barbershop_cannot_reach_the_reservation(): void
    {
        Event::fake([SpaceReleased::class]);
        $id = $this->book();
        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 09:00:00', new DateTimeZone('America/Costa_Rica')));

        $this->runTask($id, $this->fadeLab);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('temporary', DB::table('reservations')->where('id', $id)->value('status'));
        Event::assertNotDispatched(SpaceReleased::class);
    }

    /** Book Tuesday 10:00 with Daniela for Ana at 8:00, leaving the reservation temporary, and return its identifier. */
    private function book(): string
    {
        Queue::fake();
        $this->actAs($this->ana);

        return $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
    }

    /** Run the task as a worker does: the container builds what its handler asks for. */
    private function runTask(string $reservationId, string $barbershopId): void
    {
        $job = new ExpireTemporaryReservationJob($reservationId, $barbershopId);
        $this->app->call($job->handle(...));
    }
}
