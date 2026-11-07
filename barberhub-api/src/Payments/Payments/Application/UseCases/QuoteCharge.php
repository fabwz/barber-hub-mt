<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use Src\Payments\Payments\Application\Contracts\BarbershopChargePolicy;
use Src\Payments\Payments\Domain\Services\PaymentAmountCalculator;
use Src\Shared\Domain\ValueObjects\Money;

final readonly class QuoteCharge
{
    /** Receive the port that tells how the barbershop charges and the calculator of the amount. */
    public function __construct(
        private BarbershopChargePolicy $policy,
        private PaymentAmountCalculator $calculator,
    ) {}

    /**
     * Tell how much the server would charge today over each price, in the same order: nothing, the deposit rounded up
     * or the price, by the current policy of the barbershop (PA-01, PA-06). It is the very calculation of the charge,
     * and it reads the policy once whatever the number of prices.
     *
     * @param  list<int>  $prices  Colones.
     * @return list<int> Colones.
     */
    public function execute(array $prices): array
    {
        $policy = $this->policy->current();

        return array_map(
            fn (int $price): int => $policy->amountOf(Money::of($price), $this->calculator)->colones,
            $prices,
        );
    }
}
