<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Contracts;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationFilter;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatusChange;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface ReservationRepository
{
    /** Generate the identity for a new reservation. */
    public function nextId(): ReservationId;

    /**
     * Persist a new reservation, or the status and the space of an existing one, with its pending history entries in one transaction.
     * A collision the database rejects comes out as a SlotUnavailableException (the collaborator already has a reservation
     * in that time) or an OverlappingReservationException (the customer has a confirmed one).
     */
    public function save(Reservation $reservation): void;

    /**
     * Tell whether a customer role has a confirmed reservation of the current barbershop sharing some minute with the
     * interval (RE-06), leaving out the reservation being rescheduled, which cannot overlap itself. The exclusion of the
     * table is the second barrier.
     */
    public function hasConfirmedOverlapping(CustomerMembershipId $customer, TimeInterval $interval, ?ReservationId $ignoring = null): bool;

    /** Find a reservation among those of the current barbershop. */
    public function find(ReservationId $id): ?Reservation;

    /**
     * Find a reservation among those of the current barbershop and lock its row until the transaction ends, so two
     * simultaneous changes of the same reservation run one after the other.
     */
    public function findForUpdate(ReservationId $id): ?Reservation;

    /**
     * Find the temporary reservations of a collaborator in the current barbershop whose payment window closed at the
     * instant, locking their rows until the transaction ends.
     *
     * @return list<Reservation>
     */
    public function lockOverdueOf(CollaboratorId $collaborator, DateTimeImmutable $now): array;

    /**
     * Read one page of the reservations of the current barbershop that match the filter, from the earliest start to the
     * latest.
     *
     * @return Page<Reservation>
     */
    public function page(ReservationFilter $filter, PageRequest $page): Page;

    /**
     * Read the history of a reservation of the current barbershop, from the first change to the last (RE-09).
     *
     * @return list<ReservationStatusChange>
     */
    public function history(ReservationId $id): array;
}
