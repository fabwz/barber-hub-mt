<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\Exceptions\PaymentGatewayNotConfiguredException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

final readonly class ArrayPaymentGatewayRegistry implements PaymentGatewayRegistry
{
    /**
     * Keep the gateways by the value of their provider.
     *
     * @param  array<string, PaymentGateway>  $gateways
     */
    public function __construct(private array $gateways) {}

    public function for(PaymentProvider $provider): PaymentGateway
    {
        return $this->gateways[$provider->value] ?? throw PaymentGatewayNotConfiguredException::for($provider);
    }
}
