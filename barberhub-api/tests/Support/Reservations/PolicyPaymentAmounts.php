<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use Src\Payments\Payments\Application\Contracts\BarbershopChargePolicy;
use Src\Payments\Payments\Application\DTOs\ChargePolicy;
use Src\Payments\Payments\Application\UseCases\QuoteCharge;
use Src\Payments\Payments\Domain\Services\PaymentAmountCalculator;
use Src\Reservations\Reservations\Application\Contracts\PaymentAmounts;

/** The amounts a barbershop with a given policy would charge, calculated by the very use case of Payments, so the unit tests of Reservations never use another formula. */
final readonly class PolicyPaymentAmounts implements PaymentAmounts
{
    private QuoteCharge $quote;

    /** Build the quote over a fixed policy. */
    public function __construct(ChargePolicy $policy)
    {
        $this->quote = new QuoteCharge(
            new class($policy) implements BarbershopChargePolicy
            {
                public function __construct(private ChargePolicy $policy) {}

                public function current(): ChargePolicy
                {
                    return $this->policy;
                }
            },
            new PaymentAmountCalculator,
        );
    }

    public function dueFor(array $prices): array
    {
        return $this->quote->execute($prices);
    }
}
