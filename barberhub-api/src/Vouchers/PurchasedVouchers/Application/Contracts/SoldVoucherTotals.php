<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\Contracts;

use Src\Shared\Domain\ValueObjects\ReportPeriod;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherSalesDay;

interface SoldVoucherTotals
{
    /**
     * Add up, per day, the paid vouchers of the current barbershop that were activated inside the period, with the price they kept.
     * The sums are made by the database, so no voucher is loaded. A day without sales is left out.
     *
     * @return list<VoucherSalesDay> The earliest day first.
     */
    public function activatedIn(ReportPeriod $period): array;
}
