<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\ValueObjects;

enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Offered = 'offered';
    case Fulfilled = 'fulfilled';
    case Left = 'left';

    /** Whether the row still counts against the limit of the customer and keeps its place in the line (LE-01). */
    public function isActive(): bool
    {
        return $this === self::Waiting || $this === self::Offered;
    }
}
