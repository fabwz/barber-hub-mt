<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** The answers of the health route in one minute with the same result: which dependency worked, and how many times it was asked. */
final readonly class HealthCheckEntry
{
    public function __construct(
        public string $at,
        public bool $databaseOk,
        public bool $queueOk,
        public int $checks,
    ) {}

    /** Whether the route answered 200. */
    public function healthy(): bool
    {
        return $this->databaseOk && $this->queueOk;
    }
}
