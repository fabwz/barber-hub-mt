<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\Contracts;

interface VoucherSales
{
    /** Tell whether the current barbershop has the vouchers switched on, so a customer can start buying one (RF-09, BO-01). */
    public function isEnabled(): bool;
}
