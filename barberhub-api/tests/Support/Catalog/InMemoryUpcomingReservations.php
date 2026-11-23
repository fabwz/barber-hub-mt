<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use DateTimeImmutable;
use Src\Catalog\Collaborators\Application\Contracts\UpcomingReservations;
use Src\Catalog\Collaborators\Application\DTOs\UpcomingReservationList;
use Src\Catalog\Collaborators\Domain\ValueObjects\UpcomingReservation;

/** The confirmed reservations of each collaborator, kept in memory, with the rule of the port: from the instant on, nearest first. */
final class InMemoryUpcomingReservations implements UpcomingReservations
{
    /** @var array<string, list<UpcomingReservation>> */
    private array $byCollaborator = [];

    /** Give a collaborator a confirmed reservation. */
    public function add(string $collaboratorId, UpcomingReservation $reservation): void
    {
        $this->byCollaborator[$collaboratorId][] = $reservation;
    }

    /** Read the nearest confirmed reservations that start at the instant or later, and how many there are in all. */
    public function confirmedOf(string $collaboratorId, DateTimeImmutable $now, int $limit): UpcomingReservationList
    {
        $ahead = array_values(array_filter(
            $this->byCollaborator[$collaboratorId] ?? [],
            fn (UpcomingReservation $reservation): bool => $reservation->startsAt >= $now,
        ));
        usort($ahead, fn (UpcomingReservation $a, UpcomingReservation $b): int => $a->startsAt <=> $b->startsAt);

        return new UpcomingReservationList(array_slice($ahead, 0, $limit), count($ahead));
    }
}
