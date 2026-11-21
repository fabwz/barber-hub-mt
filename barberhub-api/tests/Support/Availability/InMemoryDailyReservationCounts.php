<?php

declare(strict_types=1);

namespace Tests\Support\Availability;

use DateTimeImmutable;
use Src\Availability\Slots\Application\Contracts\DailyReservationCounts;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final class InMemoryDailyReservationCounts implements DailyReservationCounts
{
    public int $reads = 0;

    /** @param array<string, int> $counts The reservations of the day by collaborator; whoever is absent has none. */
    public function __construct(private readonly array $counts = []) {}

    /**
     * Give the counts of the collaborators asked for and count the read.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, int>
     */
    public function between(array $collaboratorIds, TimeInterval $day, DateTimeImmutable $now): array
    {
        $this->reads++;

        return array_intersect_key($this->counts, array_flip($collaboratorIds));
    }
}
