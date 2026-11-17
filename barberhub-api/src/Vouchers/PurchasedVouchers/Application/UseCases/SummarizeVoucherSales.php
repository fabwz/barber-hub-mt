<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use Src\Shared\Domain\ValueObjects\ReportPeriod;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\SoldVoucherTotals;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherSalesDay;

final readonly class SummarizeVoucherSales
{
    /** Receive the port that adds up the sales. */
    public function __construct(private SoldVoucherTotals $totals) {}

    /**
     * Tell, per day, how many vouchers the current barbershop sold inside the period and for how much (RF-25, PA-11). A voucher is
     * sold on the day its payment was confirmed and it was activated (BO-02); one that still waits for its payment is not a sale.
     * It is read-only and belongs to the barbershop of the route, so no sale of another barbershop can appear (MT-02).
     *
     * @return list<VoucherSalesDay>
     */
    public function execute(ReportPeriod $period): array
    {
        return $this->totals->activatedIn($period);
    }
}
