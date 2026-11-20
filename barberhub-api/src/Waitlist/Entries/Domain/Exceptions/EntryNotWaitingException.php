<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class EntryNotWaitingException extends BusinessRuleException
{
    /** Build the exception for a row that is not waiting, such as one that already has an offer or that the customer left: it cannot receive an offer. */
    public static function create(): self
    {
        return new self('waitlist.not_waiting');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
