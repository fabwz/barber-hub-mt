<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use Src\Notifications\Notices\Application\DTOs\ReservationFacts;

/** Reads a reservation of the current barbershop through the Reservations module. */
interface ReservationLookup
{
    /** The facts of the reservation, or null when it does not exist in this barbershop. */
    public function find(string $reservationId): ?ReservationFacts;
}
