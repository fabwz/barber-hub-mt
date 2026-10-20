<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class CollaboratorAlreadyExistsException extends BusinessRuleException
{
    /** Build the exception for a person that already has a collaborator profile in the barbershop (CO-01). */
    public static function create(): self
    {
        return new self('collaborators.profile_already_exists');
    }

    /** A person has one profile per barbershop: the request clashes with the stored one. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
