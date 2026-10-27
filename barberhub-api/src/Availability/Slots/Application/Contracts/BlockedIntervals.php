<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface BlockedIntervals
{
    /**
     * The intervals some collaborators hold in a window with temporary or confirmed reservations, cleaning included (DI-05).
     * A temporary reservation whose payment window closed at `$now` holds nothing, even if it is not marked expired yet.
     *
     * A reservation being rescheduled does not block itself, so the caller names it to leave it out (RE-08).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>> By collaborator; the ones without any are absent.
     */
    public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now, ?string $ignoringReservationId = null): array;
}
