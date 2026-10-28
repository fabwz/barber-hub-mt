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
    /** Build the exception; the reason is for the log and never reaches the client. */
    public static function because(string $reason): self
    {
        return new self("The payment gateway did not accept the request: {$reason}");
    }
}
