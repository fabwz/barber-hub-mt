<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\DTOs;

/** The vouchers activated on one day of Costa Rica: how many and what they were sold for, in colones. */
final readonly class VoucherSalesDay
{
    /** Hold the day as `YYYY-MM-DD`, the number of vouchers and the sum of their prices. */
    public function __construct(public string $date, public int $count, public int $total) {}
}
