<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Src\Payments\Payments\Application\Exceptions\PaymentGatewayNotConfiguredException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

interface PaymentGatewayRegistry
{
    /**
     * The adapter of a provider.
     *
     * @throws PaymentGatewayNotConfiguredException When the system has no adapter for the provider.
     */
    public function for(PaymentProvider $provider): PaymentGateway;
}
