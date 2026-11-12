<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The language provider could not be used: it did not answer in time, could not be reached, answered 429 or a 5xx, refused our
 * credentials or has no key configured. Nothing is known about the phrase. Another issue turns it into the degraded mode or a 503.
 */
final class ExtractorUnavailableException extends RuntimeException
{
    /** Build the exception, keeping the technical cause for the log. */
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("The reservation extractor is unavailable: {$reason}", 0, $previous);
    }
}
