<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\Contracts;

use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeRange;

interface BarbershopSchedule
{
    /**
     * List the ranges the current barbershop opens on a day of the week, ordered by opening time (HO-02).
     *
     * @return list<TimeRange>
     */
    public function openRangesOn(DayOfWeek $day): array;
}
