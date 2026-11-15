<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\Contracts;

interface VoucherSales
{
    /** Tell whether the current barbershop has the vouchers switched on, so the owner can define a new one (RF-09, RF-26). */
    public function isEnabled(): bool;
}
