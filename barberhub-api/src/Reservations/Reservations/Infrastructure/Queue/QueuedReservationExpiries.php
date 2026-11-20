<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Queue;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\ReservationExpiries;
use Src\Reservations\Reservations\Infrastructure\Jobs\ExpireTemporaryReservationJob;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class QueuedReservationExpiries implements ReservationExpiries
{
    /** Receive the port that tells which barbershop the operation belongs to, to leave it in the task. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Leave a job in the queue that only becomes available when the window closes. The queue counts whole seconds and the window may close
     * in the middle of one, so the job waits one second more: it never runs before the reservation is overdue.
     */
    public function schedule(string $reservationId, DateTimeImmutable $at): void
    {
        ExpireTemporaryReservationJob::dispatch($reservationId, $this->context->current()->value)->delay($at->modify('+1 second'));
    }
}
