<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\Contracts;

use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Shared\Domain\ValueObjects\TimeRange;

interface BarbershopHours
{
    /**
     * The ranges the current barbershop is open on a date, ordered by opening time; none if it is closed that day (HO-01).
     *
     * @return list<TimeRange>
     */
    public function openRangesOn(CalendarDate $date): array;
}
