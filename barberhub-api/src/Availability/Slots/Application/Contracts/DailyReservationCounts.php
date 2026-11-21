<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface DailyReservationCounts
{
    /**
     * How many reservations each collaborator has that start inside the day, for the balance of "any available" (DI-04):
     * the confirmed ones, the temporary ones still inside their payment window, and the completed and no-show ones.
     * Cancelled and expired ones do not count, and neither does the way a reservation was paid.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, int> The count by collaborator; whoever has none is absent.
     */
    public function between(array $collaboratorIds, TimeInterval $day, DateTimeImmutable $now): array;
}
