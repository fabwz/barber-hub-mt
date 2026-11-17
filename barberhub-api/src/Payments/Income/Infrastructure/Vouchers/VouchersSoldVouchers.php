<?php

declare(strict_types=1);

namespace Src\Payments\Income\Infrastructure\Vouchers;

use Src\Payments\Income\Application\Contracts\SoldVouchers;
use Src\Payments\Income\Application\DTOs\DayAmount;
use Src\Shared\Domain\ValueObjects\ReportPeriod;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherSalesDay;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\SummarizeVoucherSales;

final readonly class VouchersSoldVouchers implements SoldVouchers
{
    /** Receive the Vouchers use case that adds up the sales. */
    public function __construct(private SummarizeVoucherSales $summarize) {}

    /**
     * Ask Vouchers for its sales and keep only the day, the number and the sum.
     *
     * @return list<DayAmount>
     */
    public function in(ReportPeriod $period): array
    {
        return array_map(fn (VoucherSalesDay $day): DayAmount => new DayAmount($day->date, $day->count, $day->total), $this->summarize->execute($period));
    }
}
