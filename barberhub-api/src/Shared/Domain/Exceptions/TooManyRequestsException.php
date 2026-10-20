<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Exceptions;

abstract class TooManyRequestsException extends DomainException
{
    /** Seconds the client must wait before trying again; the API sends them in the Retry-After header. */
    abstract public function retryAfterSeconds(): int;
}
