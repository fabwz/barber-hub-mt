<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class Shift
{
    /** Hold the range a collaborator works on a day of the week. */
    public function __construct(
        public DayOfWeek $day,
        public TimeRange $range,
    ) {}

    /** Tell whether both shifts share a day and some minute, the end excluded. */
    public function overlaps(self $other): bool
    {
        return $this->day->equals($other->day) && $this->range->overlaps($other->range);
    }
}
