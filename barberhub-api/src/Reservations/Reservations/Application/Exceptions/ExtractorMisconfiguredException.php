<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Exceptions;

use RuntimeException;

/**
 * Our configuration is wrong, not the provider: no model is set, or the provider rejected the request we built (a 4xx other than
 * 401, 403 and 429). Retrying fails the same way until someone fixes the configuration.
 */
final class ExtractorMisconfiguredException extends RuntimeException
{
    /** Build the exception; the reason never carries a key. */
    public static function because(string $reason): self
    {
        return new self("The reservation extractor is misconfigured: {$reason}");
    }
}
