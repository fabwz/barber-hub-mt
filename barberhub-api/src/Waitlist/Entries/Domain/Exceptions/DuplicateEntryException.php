<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class DuplicateEntryException extends BusinessRuleException
{
    /** Build the exception for a customer who already waits for the same service, day and collaborator. */
    public static function create(): self
    {
        return new self('waitlist.duplicate_entry');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
