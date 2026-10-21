<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;

interface FreeSlots
{
    /**
     * Whether a collaborator is free to start a service at a moment, by the same calculation the availability shows
     * (DI-01 to DI-03, DI-05). It reads the reservations again every time, so it is only as good as the lock held around it.
     */
    public function isFree(string $serviceId, string $collaboratorId, DateTimeImmutable $startsAt, DateTimeImmutable $now): bool;

    /**
     * Up to 3 free spaces of the same collaborator, service and day, the closest to the requested start (RE-02).
     *
     * @return list<SlotAlternative>
     */
    public function alternatives(string $serviceId, string $collaboratorId, DateTimeImmutable $requested, DateTimeImmutable $now): array;
}
