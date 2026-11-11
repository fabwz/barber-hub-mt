<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use RuntimeException;

/**
 * The provider could be reached but did not accept what it was sent (a 4xx other than a decision about the payment): our
 * data is of no use to it. It is not a communication error, and retrying with the same data fails again.
 */
final class GatewayRequestRejectedException extends RuntimeException
{
    private function __construct(string $message, public readonly ?string $customerReason)
    {
        parent::__construct($message);
    }

    /**
     * Build the exception; the reason is for the log and never reaches the client. The customer reason, which the adapter gives
     * in Spanish, is what the failed payment keeps as its `motivo`.
     */
    public static function because(string $reason, ?string $customerReason = null): self
    {
        return new self("The payment gateway did not accept the request: {$reason}", $customerReason);
    }
}
