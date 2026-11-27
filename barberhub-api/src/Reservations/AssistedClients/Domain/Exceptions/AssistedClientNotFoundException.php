<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class AssistedClientNotFoundException extends NotFoundException
{
    /** Build the exception for an assisted client missing from the barbershop. */
    public static function create(): self
    {
        return new self('assisted_clients.not_found');
    }
}
