<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class RoleAlreadyAssignedException extends BusinessRuleException
{
    /** Build the exception for a person that already has a role in the barbershop (UP-07). */
    public static function create(): self
    {
        return new self('identity.role_already_assigned');
    }

    /** A person has a single role per barbershop: the request clashes with the stored one. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
