<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Application\Contracts;

use DateTimeImmutable;
use Src\Catalog\Absences\Application\DTOs\ConflictingReservationList;
use Src\Shared\Domain\ValueObjects\TimeInterval;

interface ReservationConflicts
{
    /**
     * Read the reservations of a collaborator of the current barbershop that hold time inside the interval: the confirmed ones and
     * the temporary ones whose payment window is still open at the instant (DI-01). The earliest first, up to `$limit` of them,
     * with how many there are in all.
     */
    public function heldIn(string $collaboratorId, TimeInterval $interval, DateTimeImmutable $now, int $limit): ConflictingReservationList;
}
