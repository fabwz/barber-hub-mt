<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final class EmailAlreadyRegisteredException extends InvalidValueException
{
    /** Build the exception for an address that already has an account. */
    public static function create(): self
    {
        return new self('identity.email_already_registered');
    }
}
