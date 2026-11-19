<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\Contracts;

use DateTimeImmutable;
use Src\Waitlist\Entries\Domain\ValueObjects\OpenSpace;

interface OpenSpaces
{
    /**
     * The free spaces of a service on a day, of one collaborator or of any, in order of start, by the same calculation the
     * availability shows (DI-01 to DI-05). A space that already started or is too close is not listed.
     *
     * @return list<OpenSpace>
     */
    public function on(string $serviceId, string $date, ?string $collaboratorId, DateTimeImmutable $now): array;
}
