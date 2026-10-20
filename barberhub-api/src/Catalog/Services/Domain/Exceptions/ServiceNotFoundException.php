<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class ServiceNotFoundException extends NotFoundException
{
    /** Build the exception for a service missing from the barbershop. */
    public static function create(): self
    {
        return new self('services.not_found');
    }
}
