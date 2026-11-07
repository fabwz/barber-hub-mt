<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\Services\PaymentAmountCalculator;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\Percentage;

/** How a barbershop charges: not at all, the percentage of the price as a deposit, or all of it (PA-01). */
final readonly class ChargePolicy
{
    private function __construct(public bool $charges, public ?Percentage $deposit) {}

    /** The barbershop charges nothing up front. */
    public static function none(): self
    {
        return new self(false, null);
    }

    /** The barbershop charges a percentage of the price. */
    public static function deposit(Percentage $percentage): self
    {
        return new self(true, $percentage);
    }

    /** The barbershop charges the whole price. */
    public static function full(): self
    {
        return new self(true, null);
    }

    /** The amount this policy charges over a price: zero, the deposit rounded up or the price (PA-01, PA-06). Both the charge and the quote ask it here. */
    public function amountOf(Money $price, PaymentAmountCalculator $calculator): Money
    {
        if (! $this->charges) {
            return Money::zero();
        }

        return $this->deposit === null ? $calculator->total($price) : $calculator->deposit($price, $this->deposit);
    }
}
