<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class OfferAlreadyAnsweredException extends BusinessRuleException
{
    /** Build the exception for the opposite answer to the one the offer already has: an accepted offer is not rejected and a rejected one is not accepted. */
    public static function create(): self
    {
        return new self('waitlist.offer_already_answered');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
