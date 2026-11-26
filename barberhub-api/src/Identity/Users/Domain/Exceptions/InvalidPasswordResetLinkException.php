<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class InvalidPasswordResetLinkException extends InvalidValueException implements HasInvalidField
{
    /** Build the one exception for a link that is unknown, expired or already used, without saying which (SE-06). */
    public static function create(): self
    {
        return new self('identity.invalid_password_reset_link');
    }

    /** The attribute of the request that carries the token of the link. */
    public function field(): string
    {
        return 'token';
    }
}
