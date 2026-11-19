<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class EntryNotLeavableException extends BusinessRuleException
{
    /** Build the exception for a row that already ended with a reservation: there is nothing left to leave. */
    public static function create(): self
    {
        return new self('waitlist.not_leavable');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
