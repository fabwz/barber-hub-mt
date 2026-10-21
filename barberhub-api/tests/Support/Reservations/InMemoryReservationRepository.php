<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final class InMemoryReservationRepository implements ReservationRepository
{
    /** Make the next save fail as the exclusion of the database does when another reservation took the space. */
    public bool $collideOnSave = false;

    /** @var array<string, Reservation> */
    private array $reservations = [];

    /** @var list<int> The number of history entries each save wrote. */
    public array $entriesPerSave = [];

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
        $this->reservations[$reservation->id->value] = $reservation;
        $reservation->markSaved();
    }

    /** Tell whether the customer has a confirmed reservation that shares a minute with the interval. */
    public function hasConfirmedOverlapping(CustomerMembershipId $customer, TimeInterval $interval): bool
    {
        return array_any(
            $this->reservations,
            fn (Reservation $reservation): bool => $reservation->customerMembershipId->equals($customer)
                && $reservation->status === ReservationStatus::Confirmed
                && $reservation->interval->overlaps($interval),
        );
    }

    /** Find a stored reservation. */
    public function find(ReservationId $id): ?Reservation
    {
        return $this->reservations[$id->value] ?? null;
    }

    /** Count the stored reservations. */
    public function count(): int
    {
        return count($this->reservations);
    }
}
