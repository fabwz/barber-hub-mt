<?php

declare(strict_types=1);

namespace Src\Shared\Application\DTOs;

/** Which dependencies of the application worked when the health route was asked. */
final readonly class HealthReport
{
    public function __construct(
        public bool $databaseOk,
        public bool $queueOk,
    ) {}

    /** Whether every dependency works, which is what the route answers with a 200. */
    public function healthy(): bool
    {
        return $this->databaseOk && $this->queueOk;
    }
}
