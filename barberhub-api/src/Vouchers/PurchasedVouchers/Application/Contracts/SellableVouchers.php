<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\Contracts;

use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherOnSale;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;

interface SellableVouchers
{
    /**
     * The voucher on sale in the current barbershop, locked until the end of the transaction so its terms cannot change while they
     * are copied (RF-26, RF-27).
     *
     * @throws VoucherNotFoundException When it does not exist, is of another barbershop or is not on sale (MT-03).
     */
    public function lockOnSale(string $voucherDefinitionId): VoucherOnSale;
}
