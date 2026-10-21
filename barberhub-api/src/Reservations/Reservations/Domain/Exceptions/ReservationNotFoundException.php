<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class ReservationNotFoundException extends NotFoundException
{
    /**
     * Build the exception for a reservation the person cannot reach: it does not exist, belongs to another barbershop or
     * to another customer or agenda. It reads like any missing resource (UP-06, MT-03).
     */
    public static function create(): self
    {
        return new self('problems.no-encontrado.detail');
    }
}
