<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Notifications\Notices\Application\UseCases\ScheduleReminders;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\ReservationRescheduled;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

/**
 * Leaves the reminders of a reservation when it is confirmed and moves them when it is rescheduled, whoever asks for it (RF-33). It runs in a
 * worker after the transaction that caused it commits, so a failure here never undoes a reservation. The use case is idempotent, so the retries
 * of the queue and a repeated event change nothing.
 */
final class ScheduleRemindersOnReservation implements ShouldQueue
{
    /** Leave the listener in the queue only after the transaction that confirmed or moved the reservation commits. */
    public bool $afterCommit = true;

    /** Attempts before the job is recorded as failed and stays visible in the monitoring (RNF-09). */
    public int $tries = 3;

    /** @var list<int> seconds to wait before the second and third attempts */
    public array $backoff = [5, 15];

    /** Receive the scope of a process without a request, the use case that schedules the reminders and the clock. */
    public function __construct(
        private readonly BarbershopProcessScope $scope,
        private readonly ScheduleReminders $schedule,
        private readonly Clock $clock,
    ) {}

    /** Schedule the reminders for the hours the reservation has now, as the barbershop it belongs to. */
    public function handle(ReservationConfirmed|ReservationRescheduled $event): void
    {
        $this->scope->run($event->barbershopId, fn () => $this->schedule->execute($event->reservationId, $event->customerMembershipId, $event->startsAt, $this->clock->now(), $event->assistedClientId));
    }
}
