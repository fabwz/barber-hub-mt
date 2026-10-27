<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class ReservationNotPayableException extends BusinessRuleException
{
    /** Build the exception for a reservation that is no longer temporary or whose payment window closed (RE-05). */
    public static function notOpen(): self
    {
        return new self('payments.reservation_not_open');
    }

    /** Build the exception for a barbershop that does not charge, so there is nothing to pay (PA-01). */
    public static function notRequired(): self
    {
        return new self('payments.charge_not_required');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
