<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

/** Half-open range of wall-clock times within one day: the start belongs to it, the end does not. */
final readonly class TimeRange
{
    private function __construct(
        public TimeOfDay $start,
        public TimeOfDay $end,
    ) {}

    /** Build a range whose start is strictly before its end. */
    public static function between(TimeOfDay $start, TimeOfDay $end): self
    {
        if (! $start->isBefore($end)) {
            throw new InvalidValueException('shared.time_range_start_not_before_end');
        }

        return new self($start, $end);
    }

    /** Tell whether a time falls inside the range: the start is included and the end excluded. */
    public function contains(TimeOfDay $time): bool
    {
        return ! $time->isBefore($this->start) && $time->isBefore($this->end);
    }

    /** Whether both ranges share some minute; touching at an end does not count. */
    public function overlaps(self $other): bool
    {
        return $this->start->isBefore($other->end) && $other->start->isBefore($this->end);
    }

    /** Compare two ranges by their start and end. */
    public function equals(self $other): bool
    {
        return $this->start->equals($other->start) && $this->end->equals($other->end);
    }
}
