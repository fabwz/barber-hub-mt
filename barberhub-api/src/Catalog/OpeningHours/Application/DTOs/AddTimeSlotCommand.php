<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Application\DTOs;

final readonly class AddTimeSlotCommand
{
    /** Carry the data needed to add a slot to the schedule. */
    public function __construct(
        public int $day,
        public string $opensAt,
        public string $closesAt,
    ) {}
}
