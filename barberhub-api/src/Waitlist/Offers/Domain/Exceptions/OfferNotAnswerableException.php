<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class OfferNotAnswerableException extends BusinessRuleException
{
    /** Build the exception for an offer whose row of the line is not offered any more, because the customer left the line while it was pending (RF-30). */
    public static function create(): self
    {
        return new self('waitlist.offer_not_answerable');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
