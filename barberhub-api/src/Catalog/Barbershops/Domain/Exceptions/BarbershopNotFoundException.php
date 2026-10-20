<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class BarbershopNotFoundException extends NotFoundException
{
    /** Build the exception for a link that opens no barbershop (RF-02). */
    public static function create(): self
    {
        return new self('barbershops.not_found');
    }
}
