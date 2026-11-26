<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** The health route as the panel shows it. `stale` is true when nobody has asked for longer than the threshold. */
final readonly class HealthStatus
{
    /** @param  list<HealthCheckEntry>  $failures */
    public function __construct(
        public ?HealthCheckEntry $last,
        public bool $stale,
        public array $failures,
        public ?float $availabilityPercent,
        public int $totalChecks,
    ) {}
}
