<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Events;

use DateTimeImmutable;

/**
 * A reservation became confirmed, because its payment was confirmed or because it needed none (RE-07, PA-06). The waitlist reacts to it: the
 * offer that was waiting for this payment is accepted and the customer leaves the lines of that service and day (RF-32, LE-06). The event is plain
 * data and names its barbershop, as the others do.
 */
final readonly class ReservationConfirmed
{
    /** Hold the reservation, its customer role, its service and collaborator and the interval of the service, from which the day is read. */
    public function __construct(
        public string $barbershopId,
        public string $reservationId,
        public string $customerMembershipId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {}
}
