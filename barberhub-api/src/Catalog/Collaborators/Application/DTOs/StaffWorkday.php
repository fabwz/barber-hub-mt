<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class StaffWorkday
{
    /**
     * Carry what a collaborator works and rests on one day of the week.
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
