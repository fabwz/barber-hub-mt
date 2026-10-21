<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\DTOs;

use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class StaffWorkday
{
    /**
     * Carry what a collaborator who offers the service works and rests on the day.
     *
     * @param  list<TimeRange>  $shifts
     * @param  list<TimeRange>  $breaks
     */
    public function __construct(
        public string $collaboratorId,
        public array $shifts,
        public array $breaks,
    ) {}
}
