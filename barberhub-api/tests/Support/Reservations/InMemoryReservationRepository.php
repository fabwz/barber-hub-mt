<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationFilter;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatusChange;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final class InMemoryReservationRepository implements ReservationRepository
{
    /** Make the next save fail as the exclusion of the database does when another reservation took the space. */
    public bool $collideOnSave = false;

    /** @var array<string, Reservation> */
    private array $reservations = [];

    /** @var list<int> The number of history entries each save wrote. */
    public array $entriesPerSave = [];

    /** @var array<string, list<ReservationStatusChange>> */
    private array $histories = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): ReservationId
    {
        return new ReservationId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the reservation and remember how many history entries came with it. */
    public function save(Reservation $reservation): void
    {
        if ($this->collideOnSave) {
            throw SlotUnavailableException::create();
        }

        $this->entriesPerSave[] = count($reservation->pendingChanges);
        $this->histories[$reservation->id->value] = [...($this->histories[$reservation->id->value] ?? []), ...$reservation->pendingChanges];
        $this->reservations[$reservation->id->value] = $reservation;
        $reservation->markSaved();
    }

    /** Tell whether the customer has a confirmed reservation that shares a minute with the interval. */
    public function hasConfirmedOverlapping(CustomerMembershipId $customer, TimeInterval $interval, ?ReservationId $ignoring = null): bool
    {
        return array_any(
            $this->reservations,
            fn (Reservation $reservation): bool => ($ignoring === null || $reservation->id->value !== $ignoring->value)
                && $reservation->customerMembershipId->equals($customer)
                && $reservation->status === ReservationStatus::Confirmed
                && $reservation->interval->overlaps($interval),
        );
    }

    /** Find a stored reservation. */
    public function find(ReservationId $id): ?Reservation
    {
        return $this->reservations[$id->value] ?? null;
    }

    /** Find a stored reservation; nothing else runs in memory, so there is no row to lock. */
    public function findForUpdate(ReservationId $id): ?Reservation
    {
        return $this->find($id);
    }

    /**
     * Page the stored reservations that match the filter, from the earliest start to the latest.
     *
     * @return Page<Reservation>
     */
    public function page(ReservationFilter $filter, PageRequest $page): Page
    {
        $window = $filter->window();
        $matches = array_values(array_filter(
            $this->reservations,
            fn (Reservation $reservation): bool => ($filter->customer === null || $reservation->customerMembershipId->equals($filter->customer))
                && ($filter->collaborator === null || $reservation->collaboratorId->equals($filter->collaborator))
                && ($filter->status === null || $reservation->status === $filter->status)
                && ($window === null || ($reservation->interval->start >= $window->start && $reservation->interval->start < $window->end)),
        ));
        usort($matches, fn (Reservation $a, Reservation $b): int => $a->interval->start <=> $b->interval->start);

        return new Page(array_slice($matches, $page->offset(), $page->size), count($matches), $page);
    }

    /** The stored history of a reservation, in the order it was written. */
    public function history(ReservationId $id): array
    {
        return $this->histories[$id->value] ?? [];
    }

    /** Count the stored reservations. */
    public function count(): int
    {
        return count($this->reservations);
    }
}
