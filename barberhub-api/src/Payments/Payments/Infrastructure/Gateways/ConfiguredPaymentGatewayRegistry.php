<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Gateways;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\Exceptions\PaymentGatewayNotConfiguredException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/** Finds the adapter of a provider in config/payments.php and builds it through the container. */
final readonly class ConfiguredPaymentGatewayRegistry implements PaymentGatewayRegistry
{
    /** Receive the container that builds the adapters and the configuration that names them. */
    public function __construct(private Container $container, private Repository $config) {}

    /** Build the adapter configured for the provider. */
    public function for(PaymentProvider $provider): PaymentGateway
    {
        $class = $this->config->get("payments.gateways.{$provider->value}");

        if (! is_string($class)) {
            throw PaymentGatewayNotConfiguredException::for($provider);
        }

        $gateway = $this->container->make($class);

        return $gateway instanceof PaymentGateway ? $gateway : throw PaymentGatewayNotConfiguredException::for($provider);
    }
}
