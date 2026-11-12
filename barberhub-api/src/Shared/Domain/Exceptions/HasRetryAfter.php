<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Exceptions;

interface HasRetryAfter
{
    /** Seconds after which the same request may work, published as the `Retry-After` header; null when it is not known. */
    public function retryAfterSeconds(): ?int;
}
