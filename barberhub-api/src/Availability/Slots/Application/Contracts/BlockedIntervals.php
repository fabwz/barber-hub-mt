<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\Contracts;

use Src\Shared\Domain\ValueObjects\TimeInterval;

interface BlockedIntervals
{
    /**
     * The intervals some collaborators hold in a window with temporary or confirmed reservations, cleaning included (DI-05).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>> By collaborator; the ones without any are absent.
     */
    public function between(array $collaboratorIds, TimeInterval $window): array;
}
