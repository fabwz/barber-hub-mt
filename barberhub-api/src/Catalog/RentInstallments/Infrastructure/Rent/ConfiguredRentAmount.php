<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure\Rent;

use Src\Catalog\RentInstallments\Domain\Contracts\RentAmount;
use Src\Shared\Domain\ValueObjects\Money;

/**
 * The rent in force, read from `config('rent.monthly_amount')` (variable `RENT_MONTHLY_AMOUNT`). It is a provisional demonstration value until
 * PD-09 defines the real one; replacing this adapter with one that reads a value the operator edits changes nothing in the generation.
 */
final readonly class ConfiguredRentAmount implements RentAmount
{
    /** Receive the amount in whole colones. */
    public function __construct(private int $colones) {}

    /** The configured amount. */
    public function current(): Money
    {
        return Money::of($this->colones);
    }
}
