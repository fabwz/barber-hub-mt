<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use Src\Vouchers\PurchasedVouchers\Application\Contracts\VoucherSales as PurchaseVoucherSales;
use Src\Vouchers\VoucherDefinitions\Application\Contracts\VoucherSales as DefinitionVoucherSales;

/** The switch of the vouchers of the barbershop of the test; it starts on, as in the barbershop of the fixtures. */
final class InMemoryVoucherSales implements DefinitionVoucherSales, PurchaseVoucherSales
{
    /** Keep the switch; the vouchers are on unless told otherwise. */
    public function __construct(private bool $enabled = true) {}

    /** Switch the vouchers on or off. */
    public function switchTo(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /** Tell whether the vouchers are on. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
