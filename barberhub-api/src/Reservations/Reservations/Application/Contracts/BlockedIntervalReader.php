<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Shared\Domain\ValueObjects\TimeInterval;

interface BlockedIntervalReader
{
    /**
     * Read, in one query, the intervals the temporary and confirmed reservations of some collaborators hold in the
     * current barbershop, from the start of the service to the end of the cleaning, that share an instant with a window.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>> The intervals by collaborator, ordered by start; collaborators without any are absent.
     */
    public function between(array $collaboratorIds, TimeInterval $window): array;
}
