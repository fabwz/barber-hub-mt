<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class OfferExpiredException extends BusinessRuleException
{
    /** Build the exception for an answer that arrives after the 15 minutes of the offer, whether or not its task marked it expired (LE-04, LE-05). */
    public static function create(): self
    {
        return new self('waitlist.offer_expired');
    }

    /** The offer ran out before the answer: the stable type of the API. */
    public function rule(): string
    {
        return 'oferta-vencida';
    }
}
