<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The language provider could not be used: it did not answer in time, could not be reached, answered 429 or a 5xx, refused our
 * credentials, has no key configured or the circuit breaker is open. Nothing is known about the phrase. The use case turns it
 * into the 503 that invites the normal form; `retryAfterSeconds` says when it is worth trying again, when that is known.
 */
final class ExtractorUnavailableException extends RuntimeException
{
    private function __construct(string $message, public readonly ExtractorFailure $failure, public readonly ?int $retryAfterSeconds, ?Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    /** An outage of the provider, keeping the technical cause for the log. */
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("The reservation extractor is unavailable: {$reason}", ExtractorFailure::Outage, null, $previous);
    }

    /** The provider answered 429; if it said when to come back, that is `$retryAfterSeconds`. */
    public static function rateLimited(?int $retryAfterSeconds): self
    {
        return new self('The reservation extractor is unavailable: the provider answered HTTP 429.', ExtractorFailure::Outage, $retryAfterSeconds, null);
    }

    /** The provider refused our key. */
    public static function credentialsRefused(int $status): self
    {
        return new self("The reservation extractor is unavailable: the provider refused the credentials (HTTP {$status}).", ExtractorFailure::CredentialsRefused, null, null);
    }

    /** No key is configured, so the provider was not called. */
    public static function notConfigured(): self
    {
        return new self('The reservation extractor is unavailable: no key is configured (GEMINI_API_KEY).', ExtractorFailure::NotConfigured, null, null);
    }

    /** The circuit breaker is open: the provider was not called and `$retryAfterSeconds` is what is left of it. */
    public static function circuitOpen(int $retryAfterSeconds, ?Throwable $previous = null): self
    {
        return new self("The reservation extractor is unavailable: the circuit is open, retry in {$retryAfterSeconds} s.", ExtractorFailure::CircuitOpen, max(1, $retryAfterSeconds), $previous);
    }
}
