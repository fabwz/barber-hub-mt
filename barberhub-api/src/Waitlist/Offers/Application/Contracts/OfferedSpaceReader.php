<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface OfferedSpaceReader
{
    /**
     * Read, in one query, the spaces that the pending offers of some collaborators hold in the current barbershop and that share an instant
     * with a window (DI-05). An offer whose 15 minutes passed at `$now` holds nothing, even if its task has not marked it expired yet (LE-05).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>> The spaces by collaborator, ordered by start; collaborators without any are absent.
     */
    public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now): array;
}
