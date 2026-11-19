<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class WaitlistLimitReachedException extends BusinessRuleException
{
    /** Build the exception for a customer who already has the most active rows the barbershop allows (LE-01). */
    public static function create(): self
    {
        return new self('waitlist.limit_reached');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
