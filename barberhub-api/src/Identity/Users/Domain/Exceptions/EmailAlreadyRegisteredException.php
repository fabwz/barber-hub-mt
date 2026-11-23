<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class EmailAlreadyRegisteredException extends InvalidValueException implements HasInvalidField
{
    /** Build the exception for an address that already has an account. */
    public static function create(): self
    {
        return new self('identity.email_already_registered');
    }

    /** The attribute of the request that carries the email. */
    public function field(): string
    {
        return 'correo';
    }
}
