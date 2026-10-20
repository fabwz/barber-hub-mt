<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\AuthenticationFailedException;

final class InvalidCredentialsException extends AuthenticationFailedException
{
    /** Build the one exception for an unknown email, a wrong password or an inactive account. */
    public static function create(): self
    {
        return new self('identity.invalid_credentials');
    }
}
