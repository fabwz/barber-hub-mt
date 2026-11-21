<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class TemporaryPasswordMustBeChangedException extends BusinessRuleException
{
    /** Build the exception for an account that still has the temporary password it was given (UP-04). */
    public static function create(): self
    {
        return new self('identity.temporary_password_must_change');
    }

    /** Stable type of the problem the API answers with. */
    public function rule(): string
    {
        return 'contrasena-temporal';
    }
}
