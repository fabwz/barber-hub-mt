<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final class CollaboratorNotBookableException extends InvalidValueException
{
    /** Build the exception for a collaborator of the barbershop who is inactive or does not offer the service (CO-01). */
    public static function create(): self
    {
        return new self('collaborators.collaborator_not_bookable');
    }
}
