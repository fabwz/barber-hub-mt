<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Application\UseCases;

use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class ListOpenRangesOnWeekday
{
    /** Receive the time slot repository port. */
    public function __construct(private TimeSlotRepository $slots) {}

    /**
     * List the ranges the current barbershop opens on a day of the week, ordered by opening time. Closures are dates, not
     * weekdays, so they do not apply: the schedule of a collaborator is a weekly one (HO-02).
     *
     * @return list<TimeRange>
     */
    public function execute(DayOfWeek $day): array
    {
        return array_map(fn (TimeSlot $slot): TimeRange => $slot->range, $this->slots->ofDay($day));
    }
}
