<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class RescheduleLimitReachedException extends BusinessRuleException
{
    /** Build the exception for a reservation that was already rescheduled the most times it allows (CA-05). */
    public static function create(): self
    {
        return new self('reservations.reschedule_limit_reached');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'reprogramaciones-agotadas';
    }
}
