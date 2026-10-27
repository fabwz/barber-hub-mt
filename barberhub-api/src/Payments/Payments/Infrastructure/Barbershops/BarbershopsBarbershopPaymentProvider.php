<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Barbershops;

use Src\Catalog\Barbershops\Application\UseCases\GetPaymentPolicy;
use Src\Payments\Payments\Application\Contracts\BarbershopPaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

final readonly class BarbershopsBarbershopPaymentProvider implements BarbershopPaymentProvider
{
    /** Receive the Barbershops use case that gives the payment policy. */
    public function __construct(private GetPaymentPolicy $getPaymentPolicy) {}

    /** Give the gateway the current barbershop has chosen through the Barbershops module, as a provider of Payments. */
    public function current(): ?PaymentProvider
    {
        $gateway = $this->getPaymentPolicy->execute()->gateway;

        return $gateway === null ? null : PaymentProvider::from($gateway->value);
    }
}
