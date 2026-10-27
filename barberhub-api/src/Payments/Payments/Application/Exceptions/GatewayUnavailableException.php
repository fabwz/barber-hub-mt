<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Exceptions;

use RuntimeException;
use Src\Payments\Payments\Application\DTOs\ChargeResult;
use Throwable;

/**
 * The provider could not be reached or answered with an error. It is not a refusal: nothing is known about the payment,
 * except, when it failed after the charge was created, which charge it was (`created`).
 */
final class GatewayUnavailableException extends RuntimeException
{
    /** The charge the provider already had when the communication failed, or null when it failed before. */
    public private(set) ?ChargeResult $created = null;

    /** Build the exception, keeping the technical cause for the log. */
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("The payment gateway is unavailable: {$reason}", 0, $previous);
    }

    /** Build the exception for a failure after the provider created the charge: the charge exists and must be asked about later. */
    public static function afterCharge(ChargeResult $created, string $reason, ?Throwable $previous = null): self
    {
        $exception = self::because($reason, $previous);
        $exception->created = $created;

        return $exception;
    }
}
