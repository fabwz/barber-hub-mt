<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class PaymentWindowExpiredException extends BusinessRuleException
{
    /** Build the exception for a temporary reservation confirmed after its payment window closed (RE-05). */
    public static function create(): self
    {
        return new self('reservations.payment_window_expired');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
