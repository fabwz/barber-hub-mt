<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Events;

use DateTimeImmutable;

/**
 * A temporary reservation expired because its payment window closed without payment (RE-05). `SpaceReleased` says the time is free; this
 * one says what happened to the reservation, which the agenda shows (RF-36). It names its barbershop because a worker attends it.
 */
final readonly class ReservationExpired
{
    /** Hold the reservation, its collaborator and the interval of the service. */
    public function __construct(
        public string $barbershopId,
        public string $reservationId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {}
}
