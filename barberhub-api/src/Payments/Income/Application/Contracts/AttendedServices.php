<?php

declare(strict_types=1);

namespace Src\Payments\Income\Application\Contracts;

use Src\Payments\Income\Application\DTOs\DayAmount;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

interface AttendedServices
{
    /**
     * The services the current barbershop attended inside the period, per day, at the price of their reservation; Reservations decides which are attended.
     *
     * @return list<DayAmount> The earliest day first.
     */
    public function in(ReportPeriod $period): array;
}
