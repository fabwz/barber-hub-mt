<?php

declare(strict_types=1);

namespace Src\Payments\Income\Application\Contracts;

use Src\Payments\Income\Application\DTOs\DayAmount;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

interface OnlinePaymentTotals
{
    /**
     * Add up, per day, the payments of reservations of the current barbershop that were charged inside the period: the approved ones
     * and those later refunded, by the day they were created. Vouchers are not here (they have their own figure). The database makes the sums.
     *
     * @return list<DayAmount> The earliest day first.
     */
    public function chargedIn(ReportPeriod $period): array;

    /**
     * Add up, per day, the payments of reservations of the current barbershop that were refunded inside the period, by the day of the
     * refund, for their whole amount. A payment marked to give back (PA-07) is still approved and does not count until it is refunded.
     *
     * @return list<DayAmount> The earliest day first.
     */
    public function refundedIn(ReportPeriod $period): array;
}
