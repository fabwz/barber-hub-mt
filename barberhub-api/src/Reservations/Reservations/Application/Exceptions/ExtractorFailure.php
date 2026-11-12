<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Exceptions;

/** Why the language provider was unavailable, so that the circuit breaker knows whether it counts as an outage (IA-05). */
enum ExtractorFailure
{
    /** It did not answer in time, could not be reached, or answered 429 or a 5xx. */
    case Outage;

    /** It refused our credentials (401 or 403): retrying cannot work until someone fixes the key. */
    case CredentialsRefused;

    /** There is no key, so the provider was never called: it says nothing about the provider. */
    case NotConfigured;

    /** The circuit breaker is open and the provider was not called. */
    case CircuitOpen;
}
