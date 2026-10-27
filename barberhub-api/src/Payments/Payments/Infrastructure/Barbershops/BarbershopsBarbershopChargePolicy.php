<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Barbershops;

use Src\Catalog\Barbershops\Application\UseCases\GetPaymentPolicy;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentMode;
use Src\Payments\Payments\Application\Contracts\BarbershopChargePolicy;
use Src\Payments\Payments\Application\DTOs\ChargePolicy;
use Src\Shared\Domain\ValueObjects\Percentage;

final readonly class BarbershopsBarbershopChargePolicy implements BarbershopChargePolicy
{
    /** Receive the Barbershops use case that gives the payment policy. */
    public function __construct(private GetPaymentPolicy $getPaymentPolicy) {}

    /** Translate the policy of the current barbershop into how Payments charges. */
    public function current(): ChargePolicy
    {
        $policy = $this->getPaymentPolicy->execute();

        return match ($policy->mode) {
            PaymentMode::None => ChargePolicy::none(),
            PaymentMode::Full => ChargePolicy::full(),
            PaymentMode::Deposit => ChargePolicy::deposit(Percentage::of((int) $policy->depositPercentage)),
        };
    }
}
