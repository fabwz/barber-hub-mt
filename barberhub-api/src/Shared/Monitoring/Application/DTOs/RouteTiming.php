<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** How one route answered: how many requests, the average and the maximum time, and how many ended in a 5xx. */
final readonly class RouteTiming
{
    public function __construct(
        public string $method,
        public string $route,
        public int $requests,
        public int $averageMs,
        public int $maxMs,
        public int $serverErrors,
    ) {}
}
