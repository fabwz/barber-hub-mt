<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class AccountDataRequiredException extends InvalidValueException implements HasInvalidField
{
    private const string NAME_KEY = 'invitations.name_required';

    /** The email has no account yet, so the person must give their name. */
    public static function name(): self
    {
        return new self(self::NAME_KEY);
    }

    /** The email has no account yet, so the person must choose a password. */
    public static function password(): self
    {
        return new self('invitations.password_required');
    }

    /** The attribute of the request that is missing. */
    public function field(): string
    {
        return $this->translationKey() === self::NAME_KEY ? 'nombre' : 'contrasena';
    }
}
