<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use DateTimeImmutable;
use Src\Catalog\Absences\Application\Contracts\ReservationConflicts;
use Src\Catalog\Absences\Application\DTOs\ConflictingReservationList;
use Src\Catalog\Absences\Domain\ValueObjects\ConflictingReservation;
use Src\Shared\Domain\ValueObjects\TimeInterval;

/** The reservations of each collaborator that hold time, kept in memory; those that overlap the interval conflict. */
final class InMemoryReservationConflicts implements ReservationConflicts
{
    /** @var array<string, list<ConflictingReservation>> */
    private array $byCollaborator = [];

    /** Give a collaborator a reservation that holds time. */
    public function add(string $collaboratorId, ConflictingReservation $reservation): void
    {
        $this->byCollaborator[$collaboratorId][] = $reservation;
    }

    /** Remove a reservation, as the owner does when resolving it. */
    public function resolve(string $collaboratorId, string $reservationId): void
    {
        $this->byCollaborator[$collaboratorId] = array_values(array_filter(
            $this->byCollaborator[$collaboratorId] ?? [],
            fn (ConflictingReservation $reservation): bool => $reservation->reservationId !== $reservationId,
        ));
    }

    /** The earliest overlapping reservations and how many there are in all. */
    public function heldIn(string $collaboratorId, TimeInterval $interval, DateTimeImmutable $now, int $limit): ConflictingReservationList
    {
        $held = array_values(array_filter(
            $this->byCollaborator[$collaboratorId] ?? [],
            fn (ConflictingReservation $reservation): bool => $reservation->startsAt < $interval->end && $reservation->endsAt > $interval->start,
        ));
        usort($held, fn (ConflictingReservation $a, ConflictingReservation $b): int => $a->startsAt <=> $b->startsAt);

        return new ConflictingReservationList(array_slice($held, 0, $limit), count($held));
    }
}
