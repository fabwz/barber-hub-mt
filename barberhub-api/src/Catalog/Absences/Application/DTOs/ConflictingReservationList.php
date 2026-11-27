<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Application\DTOs;

use Src\Catalog\Absences\Domain\ValueObjects\ConflictingReservation;

final readonly class ConflictingReservationList
{
    /**
     * Carry the earliest reservations that fall inside an interval and how many there are in all.
     *
     * @param  list<ConflictingReservation>  $reservations
     */
    public function __construct(
        public array $reservations,
        public int $total,
    ) {}
}
