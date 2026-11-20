<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class EntryNotOfferedException extends BusinessRuleException
{
    /** Build the exception for a row that has no offer to fulfil, such as one the customer left while the offer was pending. */
    public static function create(): self
    {
        return new self('waitlist.not_offered');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
