<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Duration;

final readonly class BookableService
{
    /** Carry a service that can be booked: its duration and its price with the VAT split into base and tax (RL-02). */
    public function __construct(
        public string $id,
        public string $name,
        public Duration $duration,
        public int $price,
        public int $base,
        public int $tax,
    ) {}
}
