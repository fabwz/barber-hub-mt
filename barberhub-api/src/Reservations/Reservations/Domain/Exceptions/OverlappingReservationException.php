<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class OverlappingReservationException extends BusinessRuleException
{
    /** Build the exception for a customer who already has a confirmed reservation at that time in the barbershop (RE-06). */
    public static function create(): self
    {
        return new self('reservations.customer_overlap');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'reserva-solapada';
    }
}
