<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use RuntimeException;
use Throwable;

/** The provider could not be reached or answered with an error. It is not a refusal: nothing is known about the payment. */
final class GatewayUnavailableException extends RuntimeException
{
    /** Build the exception, keeping the technical cause for the log. */
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("The payment gateway is unavailable: {$reason}", 0, $previous);
    }
}
