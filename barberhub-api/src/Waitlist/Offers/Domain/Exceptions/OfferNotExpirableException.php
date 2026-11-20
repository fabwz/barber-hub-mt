<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class OfferNotExpirableException extends BusinessRuleException
{
    /** Build the exception for an offer that is not pending or whose 15 minutes have not passed: only a pending, overdue one expires. */
    public static function create(): self
    {
        return new self('waitlist.offer_not_expirable');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
