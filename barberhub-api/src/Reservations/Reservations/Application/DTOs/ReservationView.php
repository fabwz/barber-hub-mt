<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use Src\Reservations\Reservations\Domain\Entities\Reservation;

final readonly class ReservationView
{
    /** Carry a reservation with the names of its service, collaborator and, for the staff, customer. */
    public function __construct(
        public Reservation $reservation,
        public string $serviceName,
        public string $collaboratorName,
        public ?string $customerName,
    ) {}
}
