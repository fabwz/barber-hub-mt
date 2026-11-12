<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class ReservationAssistantUnavailableException extends BusinessRuleException
{
    /** The reservation by phrase cannot answer now, so the person books with the usual form (IA-05). */
    public static function create(): self
    {
        return new self('reservations.assistant_unavailable');
    }

    /** The stable type 503 of the API. */
    public function rule(): string
    {
        return 'servicio-no-disponible';
    }
}
