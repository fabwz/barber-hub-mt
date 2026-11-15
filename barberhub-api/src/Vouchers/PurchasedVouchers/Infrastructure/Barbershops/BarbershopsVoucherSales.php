<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Barbershops;

use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopFeatures;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\VoucherSales;

final readonly class BarbershopsVoucherSales implements VoucherSales
{
    /** Receive the Barbershops use case that gives the switches. */
    public function __construct(private GetBarbershopFeatures $getFeatures) {}

    /** Tell whether the current barbershop sells vouchers, through the Barbershops module. */
    public function isEnabled(): bool
    {
        return $this->getFeatures->execute()->vouchersEnabled;
    }
}
