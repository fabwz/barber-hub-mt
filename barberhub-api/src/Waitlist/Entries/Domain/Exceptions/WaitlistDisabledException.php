<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class WaitlistDisabledException extends BusinessRuleException
{
    /** Build the exception for a barbershop that has the waitlist switched off: nobody can join it (RF-09, LE-01). */
    public static function create(): self
    {
        return new self('waitlist.disabled');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
