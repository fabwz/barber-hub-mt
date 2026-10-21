<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\OpeningHours;

use Src\Availability\Slots\Application\Contracts\BarbershopHours;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Catalog\OpeningHours\Application\UseCases\ListOpenRangesOnDate;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class OpeningHoursBarbershopHours implements BarbershopHours
{
    /** Receive the OpeningHours use case that lists the ranges open on a date, closures included. */
    public function __construct(private ListOpenRangesOnDate $listOpenRanges) {}

    /**
     * List the ranges the current barbershop is open on a date through the OpeningHours module; the midnight of the
     * date in Costa Rica makes that module read the right day.
     *
     * @return list<TimeRange>
     */
    public function openRangesOn(CalendarDate $date): array
    {
        return $this->listOpenRanges->execute($date->at(0));
    }
}
