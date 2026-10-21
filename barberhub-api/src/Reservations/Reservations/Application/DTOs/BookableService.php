<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;

final readonly class BookableService
{
    /** Carry the price and the duration a reservation keeps a copy of (SV-03). */
    public function __construct(
        public Money $price,
        public Duration $duration,
    ) {}
}
