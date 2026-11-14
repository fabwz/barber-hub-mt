<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use Src\Vouchers\PurchasedVouchers\Application\Contracts\SellableVouchers;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherOnSale;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;

/** The vouchers on sale of the test, taken from definitions; one that is withdrawn or was never added reads as missing. */
final class InMemorySellableVouchers implements SellableVouchers
{
    /** @var array<string, VoucherDefinition> */
    private array $definitions = [];

    /** @var list<string> The definitions the use case locked, in order. */
    public array $locked = [];

    /** Put a definition up for the test. */
    public function add(VoucherDefinition $definition): void
    {
        $this->definitions[$definition->id->value] = $definition;
    }

    /** The definition on sale as the terms a purchase copies; it records that it was locked. */
    public function lockOnSale(string $voucherDefinitionId): VoucherOnSale
    {
        $definition = $this->definitions[$voucherDefinitionId] ?? throw VoucherNotFoundException::create();

        if (! $definition->isActive) {
            throw VoucherNotFoundException::create();
        }

        $this->locked[] = $voucherDefinitionId;

        return new VoucherOnSale($definition->id, $definition->name, $definition->price, $definition->sessionsCount, $definition->validityDays);
    }
}
