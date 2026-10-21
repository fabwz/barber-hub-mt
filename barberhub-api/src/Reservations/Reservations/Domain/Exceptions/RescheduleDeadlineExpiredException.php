<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class RescheduleDeadlineExpiredException extends BusinessRuleException
{
    /** Build the exception for a customer who reschedules after the free-cancellation deadline copied into the reservation (CA-01). */
    public static function create(): self
    {
        return new self('reservations.reschedule_deadline_expired');
    }

    /** The deadline is over: the stable type of RNF-17. */
    public function rule(): string
    {
        return 'plazo-vencido';
    }
}
