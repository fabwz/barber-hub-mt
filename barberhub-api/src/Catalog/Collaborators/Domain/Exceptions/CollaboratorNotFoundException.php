<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class CollaboratorNotFoundException extends NotFoundException
{
    /** Build the exception for a collaborator that does not exist in the barbershop. */
    public static function create(): self
    {
        return new self('collaborators.collaborator_not_found');
    }
}
