<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class CollaboratorNotFoundException extends NotFoundException
{
    /** Build the exception for a collaborator that is not in the barbershop; it reads like any missing resource (UP-06, MT-03). */
    public static function create(): self
    {
        return new self('problems.no-encontrado.detail');
    }
}
