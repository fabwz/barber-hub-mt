<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\Contracts;

use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherForSale;

interface OnSaleVouchers
{
    /**
     * One page of the vouchers on sale in the current barbershop, by name, each with the services it covers; the withdrawn ones are
     * left out (RF-26, RF-27).
     *
     * @return Page<VoucherForSale>
     */
    public function page(PageRequest $page): Page;
}
