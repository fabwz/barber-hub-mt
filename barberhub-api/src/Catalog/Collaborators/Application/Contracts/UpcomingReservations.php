<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\Contracts;

use DateTimeImmutable;
use Src\Catalog\Collaborators\Application\DTOs\UpcomingReservationList;

interface UpcomingReservations
{
    /**
     * Read the confirmed reservations of a collaborator of the current barbershop that start at the instant or later, the
     * nearest first, up to `$limit` of them, with how many there are in all (CO-03).
     */
    public function confirmedOf(string $collaboratorId, DateTimeImmutable $now, int $limit): UpcomingReservationList;
}
