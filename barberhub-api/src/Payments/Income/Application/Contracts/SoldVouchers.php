<?php

declare(strict_types=1);

namespace Src\Payments\Income\Application\Contracts;

use Src\Payments\Income\Application\DTOs\DayAmount;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

interface SoldVouchers
{
    /**
     * The vouchers the current barbershop sold inside the period, per day, with the price they kept; Vouchers decides which are sold.
     *
     * @return list<DayAmount> The earliest day first.
     */
    public function in(ReportPeriod $period): array;
}
