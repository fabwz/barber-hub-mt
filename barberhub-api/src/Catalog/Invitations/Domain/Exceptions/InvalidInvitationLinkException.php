<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class InvalidInvitationLinkException extends InvalidValueException implements HasInvalidField
{
    /** Build the one exception for a link that is unknown, expired, used or cancelled, without saying which (UP-04). */
    public static function create(): self
    {
        return new self('invitations.invalid_link');
    }

    /** The attribute of the request that carries the token of the link. */
    public function field(): string
    {
        return 'token';
    }
}
