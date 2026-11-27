<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class AssistedCustomerNotFoundException extends NotFoundException
{
    /** Build the exception for an assisted client that does not exist in the barbershop. */
    public static function create(): self
    {
        return new self('reservations.assisted_client_not_found');
    }
}
