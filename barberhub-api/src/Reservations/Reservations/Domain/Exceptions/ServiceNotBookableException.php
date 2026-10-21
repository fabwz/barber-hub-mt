<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class ServiceNotBookableException extends NotFoundException
{
    /**
     * Build the exception for a service that does not exist in the barbershop, is inactive (SV-04) or belongs to another
     * barbershop. It carries the message of every missing resource, so none of those cases (nor a barbershop that does not
     * exist) can be told apart from the others (UP-06, MT-03).
     */
    public static function create(): self
    {
        return new self('problems.no-encontrado.detail');
    }
}
