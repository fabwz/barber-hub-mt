<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\ReservationCounter;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class CountReservationsPerCollaborator
{
    /** Receive the port that counts reservations. */
    public function __construct(private ReservationCounter $counter) {}

    /**
     * Count the reservations each collaborator has that start inside a window, for the balance of "any available" that
     * the availability makes (DI-04). How a reservation was paid does not matter.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, int>
     */
    public function execute(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now): array
    {
        if ($collaboratorIds === []) {
            return [];
        }

        return $this->counter->startingIn($collaboratorIds, $window, $now);
    }
}
