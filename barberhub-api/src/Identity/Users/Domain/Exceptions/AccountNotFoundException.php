<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class AccountNotFoundException extends NotFoundException
{
    /** Build the exception for a session whose account no longer exists. */
    public static function create(): self
    {
        return new self('identity.account_not_found');
    }
}
