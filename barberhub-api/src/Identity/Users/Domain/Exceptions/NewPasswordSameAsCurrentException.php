<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class NewPasswordSameAsCurrentException extends InvalidValueException implements HasInvalidField
{
    /** Build the exception for a new password that is the one the account already has. */
    public static function create(): self
    {
        return new self('identity.new_password_same_as_current');
    }

    /** The attribute of the request that carries the new password. */
    public function field(): string
    {
        return 'contrasena_nueva';
    }
}
