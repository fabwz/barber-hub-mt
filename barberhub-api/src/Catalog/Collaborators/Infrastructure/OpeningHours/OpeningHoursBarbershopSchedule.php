<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\OpeningHours;

use Src\Catalog\Collaborators\Application\Contracts\BarbershopSchedule;
use Src\Catalog\OpeningHours\Application\UseCases\ListOpenRangesOnWeekday;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class OpeningHoursBarbershopSchedule implements BarbershopSchedule
{
    /** Receive the OpeningHours use case that lists the ranges of a weekday. */
    public function __construct(private ListOpenRangesOnWeekday $listOpenRanges) {}

    /**
     * List the ranges the current barbershop opens on a day of the week through the OpeningHours module.
     *
     * @return list<TimeRange>
     */
    public function openRangesOn(DayOfWeek $day): array
    {
        return $this->listOpenRanges->execute($day);
    }
}
