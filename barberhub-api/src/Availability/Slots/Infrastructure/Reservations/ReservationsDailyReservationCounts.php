<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Reservations;

use DateTimeImmutable;
use Src\Availability\Slots\Application\Contracts\DailyReservationCounts;
use Src\Reservations\Reservations\Application\UseCases\CountReservationsPerCollaborator;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class ReservationsDailyReservationCounts implements DailyReservationCounts
{
    /** Receive the Reservations use case that counts the reservations of some collaborators in a window. */
    public function __construct(private CountReservationsPerCollaborator $count) {}

    /**
     * Count the reservations of some collaborators that start inside the day through the Reservations module.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, int>
     */
    public function between(array $collaboratorIds, TimeInterval $day, DateTimeImmutable $now): array
    {
        return $this->count->execute($collaboratorIds, $day, $now);
    }
}
