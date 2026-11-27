<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\NotFoundException;

final class InvitationNotFoundException extends NotFoundException
{
    /** Build the exception for an invitation that does not exist in the barbershop, or is no longer pending. */
    public static function create(): self
    {
        return new self('invitations.invitation_not_found');
    }
}
