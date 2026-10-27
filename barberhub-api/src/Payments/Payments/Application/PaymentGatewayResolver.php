<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application;

use Src\Payments\Payments\Application\Contracts\BarbershopPaymentProvider;
use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentMethodNotSupportedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/**
 * Chooses the gateway for a payment. A new charge goes through the provider the barbershop has now or, when it has none,
 * the default one of the system; a charge already created keeps the provider it was created with, so changing the one of the
 * barbershop does not touch a payment in course (PA-02, RF-23).
 */
final readonly class PaymentGatewayResolver
{
    /** Receive the provider of the current barbershop, the adapters and the provider used when the barbershop has none. */
    public function __construct(
        private BarbershopPaymentProvider $barbershop,
        private PaymentGatewayRegistry $gateways,
        private PaymentProvider $default,
    ) {}

    /**
     * The gateway for a new charge with the method, which the provider must admit.
     *
     * @throws PaymentMethodNotSupportedException When the provider does not admit the method.
     */
    public function forNewCharge(PaymentMethod $method): PaymentGateway
    {
        $gateway = $this->gateways->for($this->barbershop->current() ?? $this->default);

        if (! $gateway->supports($method)) {
            throw PaymentMethodNotSupportedException::create($method, $gateway->provider());
        }

        return $gateway;
    }

    /** The gateway of a payment already created: the one of its own provider, whatever the barbershop uses now. */
    public function forPayment(Payment $payment): PaymentGateway
    {
        return $this->gateways->for($payment->provider);
    }
}
