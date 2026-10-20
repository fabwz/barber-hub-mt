<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class ClosureAlreadyExistsException extends DomainException
{
    /** Build the exception for a date the barbershop already closed. */
    public static function create(): self
    {
        return new self('closures.already_exists');
    }
}
