<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Contracts;

use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface ReservationRepository
{
    /** Generate the identity for a new reservation. */
    public function nextId(): ReservationId;

    /**
     * Persist a new reservation, or the status of an existing one, with its pending history entries in one transaction.
     * A collision the database rejects comes out as a SlotUnavailableException (the collaborator already has a reservation
     * in that time) or an OverlappingReservationException (the customer has a confirmed one).
     */
    public function save(Reservation $reservation): void;

    /**
     * Tell whether a customer role has a confirmed reservation of the current barbershop sharing some minute with the
     * interval (RE-06). The exclusion of the table is the second barrier.
     */
    public function hasConfirmedOverlapping(CustomerMembershipId $customer, TimeInterval $interval): bool;

    /** Find a reservation among those of the current barbershop. */
    public function find(ReservationId $id): ?Reservation;
}
