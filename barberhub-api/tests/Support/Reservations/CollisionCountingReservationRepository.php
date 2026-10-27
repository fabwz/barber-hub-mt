<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationFilter;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatusChange;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Shared\Domain\ValueObjects\TimeInterval;

/**
 * Wraps the real repository and counts the reservations the exclusion of the database refused (the second barrier,
 * ADR 0009). The use case checks the space before saving, so a reservation only gets to the exclusion when the first
 * barrier, the lock of the collaborator, did not keep the requests apart.
 */
final class CollisionCountingReservationRepository implements ReservationRepository
{
    public int $collisions = 0;

    /** Keep the repository that does the work. */
    public function __construct(private readonly ReservationRepository $inner) {}

    /** Delegate. */
    public function nextId(): ReservationId
    {
        return $this->inner->nextId();
    }

    /** Save through the real repository and count the refusal of the exclusion of the collaborator, which it translates to SlotUnavailableException. */
    public function save(Reservation $reservation): void
    {
        try {
            $this->inner->save($reservation);
        } catch (SlotUnavailableException $exception) {
            $this->collisions++;

            throw $exception;
        }
    }

    /** Delegate. */
    public function hasConfirmedOverlapping(CustomerMembershipId $customer, TimeInterval $interval, ?ReservationId $ignoring = null): bool
    {
        return $this->inner->hasConfirmedOverlapping($customer, $interval, $ignoring);
    }

    /** Delegate. */
    public function find(ReservationId $id): ?Reservation
    {
        return $this->inner->find($id);
    }

    /** Delegate. */
    public function findForUpdate(ReservationId $id): ?Reservation
    {
        return $this->inner->findForUpdate($id);
    }

    /**
     * Delegate.
     *
     * @return list<Reservation>
     */
    public function lockOverdueOf(CollaboratorId $collaborator, DateTimeImmutable $now): array
    {
        return $this->inner->lockOverdueOf($collaborator, $now);
    }

    /**
     * Delegate.
     *
     * @return Page<Reservation>
     */
    public function page(ReservationFilter $filter, PageRequest $page): Page
    {
        return $this->inner->page($filter, $page);
    }

    /**
     * Delegate.
     *
     * @return list<ReservationStatusChange>
     */
    public function history(ReservationId $id): array
    {
        return $this->inner->history($id);
    }
}
