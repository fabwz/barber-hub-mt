<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Domain\Entities;

use Src\Catalog\OpeningHours\Domain\ValueObjects\TimeSlotId;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class TimeSlot
{
    /** Hold the full state of a time slot. */
    private function __construct(
        public TimeSlotId $id,
        public DayOfWeek $day,
        public TimeRange $range,
    ) {}

    /** Register a new slot of the weekly schedule. */
    public static function create(TimeSlotId $id, DayOfWeek $day, TimeRange $range): self
    {
        return new self($id, $day, $range);
    }

    /** Rebuild a slot from its stored state. */
    public static function reconstitute(TimeSlotId $id, DayOfWeek $day, TimeRange $range): self
    {
        return new self($id, $day, $range);
    }

    /** Tell whether both slots share a day and some minute, the closing excluded (HO-01). */
    public function overlaps(self $other): bool
    {
        return $this->day->equals($other->day) && $this->range->overlaps($other->range);
    }
}
