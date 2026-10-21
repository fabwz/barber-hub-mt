<?php

declare(strict_types=1);

namespace Tests\Support\Availability;

use Src\Availability\Slots\Application\Contracts\BarbershopHours;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Shared\Domain\ValueObjects\TimeRange;

final class InMemoryBarbershopHours implements BarbershopHours
{
    /** @param array<int, list<TimeRange>> $weekly The open ranges by day of the week. */
    public function __construct(private readonly array $weekly) {}

    /**
     * List the ranges open on the day of the week of a date.
     *
     * @return list<TimeRange>
     */
    public function openRangesOn(CalendarDate $date): array
    {
        return $this->weekly[$date->dayOfWeek()->value] ?? [];
    }
}
