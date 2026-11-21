<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface ReservationCounter
{
    /**
     * Count, in one query, the reservations of some collaborators in the current barbershop that start inside a window and
     * still weigh on their day: confirmed, temporary inside their payment window at `$now`, completed and no-show. Cancelled and
     * expired reservations do not count (DI-04).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, int> The count by collaborator; collaborators without any are absent.
     */
    public function startingIn(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now): array;
}
