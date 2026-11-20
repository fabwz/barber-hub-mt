<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class OfferAwaitingPaymentException extends BusinessRuleException
{
    /** Build the exception for turning down an offer whose reservation waits for its payment: the person must cancel that reservation, or let it lapse, first. */
    public static function create(): self
    {
        return new self('waitlist.offer_awaiting_payment');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
