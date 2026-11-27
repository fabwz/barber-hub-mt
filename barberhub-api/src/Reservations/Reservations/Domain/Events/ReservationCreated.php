<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Events;

use DateTimeImmutable;

/**
 * A reservation was created as temporary, holding the time of a collaborator while its payment is awaited (RE-05). A reservation that is
 * born confirmed publishes `ReservationConfirmed` instead; this one is only for the temporary kind, which already shows in the agenda
 * (RF-36). The event is plain data and names its barbershop, as the others do, because a worker attends it.
 */
final readonly class ReservationCreated
{
    /** Hold the reservation, its customer role, its service and collaborator and the interval of the service. */
    public function __construct(
        public string $barbershopId,
        public string $reservationId,
        public ?string $customerMembershipId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {}
}
