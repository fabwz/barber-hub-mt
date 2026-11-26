<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** How many times the health route was asked in a window, and how many of those it answered 200. */
final readonly class HealthTotals
{
    public function __construct(
        public int $total,
        public int $healthy,
    ) {}
}
