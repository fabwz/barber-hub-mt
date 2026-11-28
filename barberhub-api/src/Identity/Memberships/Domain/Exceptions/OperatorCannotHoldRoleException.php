<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class OperatorCannotHoldRoleException extends BusinessRuleException
{
    /** Build the exception for an account of the platform operator that is given a role in a barbershop (PL-01, PL-02). */
    public static function create(): self
    {
        return new self('identity.operator_cannot_hold_role');
    }

    /** The account holds the condition of operator, which excludes every role of a barbershop: the request clashes with it. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
