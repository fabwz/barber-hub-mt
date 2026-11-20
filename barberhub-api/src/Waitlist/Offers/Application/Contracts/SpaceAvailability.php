<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface SpaceAvailability
{
    /**
     * Whether a space can be offered for a service: the collaborator is free to start it exactly when the space starts, by the same calculation
     * the availability shows (DI-01 to DI-03), and no reservation and no other offer holds any minute of the whole space, which is what the
     * offer is going to hold (DI-05). It reads again every time, so it is only as good as the lock of the collaborator held around it.
     */
    public function isFree(string $serviceId, string $collaboratorId, TimeInterval $space, DateTimeImmutable $now): bool;
}
