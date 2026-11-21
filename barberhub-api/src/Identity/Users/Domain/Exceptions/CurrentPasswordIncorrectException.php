<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class CurrentPasswordIncorrectException extends InvalidValueException implements HasInvalidField
{
    /** Build the exception for a current password that does not match the stored one. */
    public static function create(): self
    {
        return new self('identity.current_password_incorrect');
    }

    /** The attribute of the request that carries the current password. */
    public function field(): string
    {
        return 'contrasena_actual';
    }
}
