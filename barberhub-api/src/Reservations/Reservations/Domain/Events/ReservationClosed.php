<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Events;

use DateTimeImmutable;

/**
 * A confirmed reservation was closed as attended or as an absence (RF-18). It is published once, when the status really changes, not when a
 * repeated closing finds the outcome already set (CA-06). `status` is the value of the final status: `completed` or `no_show`. It names its
 * barbershop because a worker attends it.
 */
final readonly class ReservationClosed
{
    /** Hold the reservation, its collaborator and interval, and the final status it reached. */
    public function __construct(
        public string $barbershopId,
        public string $reservationId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public string $status,
    ) {}
}
