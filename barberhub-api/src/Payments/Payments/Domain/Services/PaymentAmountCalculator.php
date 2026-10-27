<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Services;

use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\Percentage;

/** Decides how much the customer pays, from the price copied into the reservation and the policy of the barbershop; never from the request (PA-06). */
final readonly class PaymentAmountCalculator
{
    /** The full payment is the price of the reservation. */
    public function total(Money $price): Money
    {
        return $price;
    }

    /** The deposit is the percentage of the price rounded up to the next colon: ₡7.000 at 30 % is ₡2.100 and ₡5.001 at 33 % is ₡1.651. */
    public function deposit(Money $price, Percentage $percentage): Money
    {
        return $price->percentage($percentage);
    }
}
