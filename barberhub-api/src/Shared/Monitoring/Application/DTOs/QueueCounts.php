<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** What a queue holds right now: tasks ready to run, tasks waiting for their time, tasks a worker holds, and how long the oldest ready one waits. */
final readonly class QueueCounts
{
    public function __construct(
        public int $pending,
        public int $delayed,
        public int $reserved,
        public int $oldestSeconds,
    ) {}
}
