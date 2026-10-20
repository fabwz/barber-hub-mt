<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\OpeningHours\Application\Contracts\ClosedDates;
use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class ListOpenRangesOnDate
{
    /** Receive the time slot repository and the closed dates ports. */
    public function __construct(
        private TimeSlotRepository $slots,
        private ClosedDates $closures,
    ) {}

    /**
     * List the ranges the current barbershop opens on a date, ordered by opening time; none if the date has a closure.
     * The date is read in its own time zone, which the caller sets to Costa Rica (HO-03).
     *
     * @return list<TimeRange>
     */
    public function execute(DateTimeImmutable $date): array
    {
        if ($this->closures->isClosedOn($date)) {
            return [];
        }

        return array_map(
            fn (TimeSlot $slot): TimeRange => $slot->range,
            $this->slots->ofDay(DayOfWeek::fromMoment($date)),
        );
    }
}
