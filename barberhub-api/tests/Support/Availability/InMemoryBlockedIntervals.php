<?php

declare(strict_types=1);

namespace Tests\Support\Availability;

use DateTimeImmutable;
use Src\Availability\Slots\Application\Contracts\BlockedIntervals;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final class InMemoryBlockedIntervals implements BlockedIntervals
{
    public int $reads = 0;

    /** @param array<string, list<TimeInterval>> $blocked The intervals held by collaborator. */
    public function __construct(private readonly array $blocked = []) {}

    /**
     * Give the intervals of the collaborators asked for and count the read.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now, ?string $ignoringReservationId = null): array
    {
        $this->reads++;

        return array_intersect_key($this->blocked, array_flip($collaboratorIds));
    }
}
