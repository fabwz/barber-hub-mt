<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\DomainException;

final class TransitionNotAllowedException extends DomainException
{
    /** Build the exception for a jump the status diagram of RE-04 does not have, or a change after a final status. */
    public static function create(): self
    {
        return new self('reservations.transition_not_allowed');
    }
}
