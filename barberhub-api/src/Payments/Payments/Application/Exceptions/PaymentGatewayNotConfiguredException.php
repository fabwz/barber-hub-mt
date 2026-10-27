<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use RuntimeException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/** The system has no adapter for a provider: a configuration error, not something the customer can fix. */
final class PaymentGatewayNotConfiguredException extends RuntimeException
{
    /** Build the exception for a provider with no adapter. */
    public static function for(PaymentProvider $provider): self
    {
        return new self("There is no payment gateway configured for the provider {$provider->value}.");
    }
}
