<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

use Src\Catalog\Collaborators\Domain\ValueObjects\UpcomingReservation;

final readonly class UpcomingReservationList
{
    /**
     * Carry the nearest confirmed reservations of a collaborator and how many there are in all.
     *
     * @param  list<UpcomingReservation>  $reservations
     */
    public function __construct(
        public array $reservations,
        public int $total,
    ) {}
}
