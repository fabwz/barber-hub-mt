<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog;

use Src\Vouchers\PurchasedVouchers\Application\Contracts\SellableVouchers;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherOnSale;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\LockVoucherDefinitionOnSale;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\VoucherDefinitionNotFoundException;

final readonly class VoucherDefinitionsSellableVouchers implements SellableVouchers
{
    /** Receive the VoucherDefinitions use case that reads and locks a voucher on sale. */
    public function __construct(private LockVoucherDefinitionOnSale $lockOnSale) {}

    /** Read the voucher through VoucherDefinitions: one that is not on sale in the barbershop reads like one that does not exist. */
    public function lockOnSale(string $voucherDefinitionId): VoucherOnSale
    {
        try {
            $definition = $this->lockOnSale->execute($voucherDefinitionId);
        } catch (VoucherDefinitionNotFoundException) {
            throw VoucherNotFoundException::create();
        }

        return new VoucherOnSale($definition->id, $definition->name, $definition->price, $definition->sessionsCount, $definition->validityDays);
    }
}
