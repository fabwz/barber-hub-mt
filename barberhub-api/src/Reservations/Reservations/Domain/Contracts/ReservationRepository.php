<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Contracts;

use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;

interface ReservationRepository
{
    /** Generate the identity for a new reservation. */
    public function nextId(): ReservationId;

    /** Persist a new reservation, or the status of an existing one, with its pending history entries in one transaction. */
    public function save(Reservation $reservation): void;

    /** Find a reservation among those of the current barbershop. */
    public function find(ReservationId $id): ?Reservation;
}
