<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use Src\Payments\Payments\Application\Contracts\BarbershopChargePolicy;
use Src\Payments\Payments\Application\DTOs\PaymentChannel;
use Src\Payments\Payments\Application\PaymentGatewayResolver;

final readonly class QuoteCurrentPaymentChannel
{
    /** Receive the charge policy of the current barbershop and the resolver that chooses the gateway. */
    public function __construct(private BarbershopChargePolicy $policy, private PaymentGatewayResolver $gateways) {}

    /**
     * Tell the provider the current barbershop collects through now and the methods it admits (PA-02), as a reservation without a
     * payment in course is told. A barbershop that does not charge has no gateway, so the answer is null (PA-01).
     */
    public function execute(): ?PaymentChannel
    {
        if (! $this->policy->current()->charges) {
            return null;
        }

        return PaymentChannel::ofGateway($this->gateways->forCurrentBarbershop());
    }
}
