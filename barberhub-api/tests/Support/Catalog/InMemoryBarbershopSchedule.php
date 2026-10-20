<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Collaborators\Application\Contracts\BarbershopSchedule;
use Src\Catalog\OpeningHours\Application\UseCases\ListOpenRangesOnWeekday;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeRange;

/** The barbershop schedule port answered by the OpeningHours use case over in-memory slots, as the adapter does. */
final readonly class InMemoryBarbershopSchedule implements BarbershopSchedule
{
    public function __construct(private InMemoryTimeSlotRepository $slots) {}

    /**
     * List the ranges the barbershop opens on a day of the week.
     *
     * @return list<TimeRange>
     */
    public function openRangesOn(DayOfWeek $day): array
    {
        return new ListOpenRangesOnWeekday($this->slots)->execute($day);
    }
}
