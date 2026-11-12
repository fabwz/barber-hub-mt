<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Exceptions;

use RuntimeException;
use Throwable;

/** The provider answered, but not with the shape, the types or the lengths expected: the answer is discarded whole, never used in part. */
final class ExtractionInvalidException extends RuntimeException
{
    /** Build the exception; the reason is for the log and never carries the content of the answer. */
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("The reservation extractor answered something invalid: {$reason}", 0, $previous);
    }
}
