<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class ClosureNotFoundException extends NotFoundException
{
    /** Build the exception for a closure missing from the barbershop. */
    public static function create(): self
    {
        return new self('closures.not_found');
    }
}
