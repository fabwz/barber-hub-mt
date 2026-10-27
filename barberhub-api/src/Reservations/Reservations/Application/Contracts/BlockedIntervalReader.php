<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface BlockedIntervalReader
{
    /**
     * Read, in one query, the intervals the temporary and confirmed reservations of some collaborators hold in the
     * current barbershop, from the start of the service to the end of the cleaning, that share an instant with a window.
     * A temporary reservation whose payment window closed at `$now` holds nothing, even if it is not marked expired yet (DI-05).
     *
     * @param  list<string>  $collaboratorIds
     * @param  string|null  $ignoringReservationId  A reservation to leave out, the one being rescheduled (RE-08).
     * @return array<string, list<TimeInterval>> The intervals by collaborator, ordered by start; collaborators without any are absent.
     */
    public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now, ?string $ignoringReservationId = null): array;
}
