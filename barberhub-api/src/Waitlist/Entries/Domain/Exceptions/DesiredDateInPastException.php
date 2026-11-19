<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class DesiredDateInPastException extends InvalidValueException implements HasInvalidField
{
    /** Build the exception for a day that has already passed in Costa Rica: nobody can be offered a space on it (LE-01). */
    public static function create(): self
    {
        return new self('waitlist.date_in_past');
    }

    /** The attribute of the request that carries the day. */
    public function field(): string
    {
        return 'fecha';
    }
}
