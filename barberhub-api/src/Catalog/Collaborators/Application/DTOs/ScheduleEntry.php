<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

final readonly class ScheduleEntry
{
    /** Carry a shift or a break: a day of the week and the times it starts and ends. */
    public function __construct(
        public int $day,
        public string $startsAt,
        public string $endsAt,
    ) {}
}
