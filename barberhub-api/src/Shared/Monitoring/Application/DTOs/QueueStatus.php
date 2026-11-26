<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** One queue as the panel shows it; `late` marks a ready task that waited longer than the alert threshold. */
final readonly class QueueStatus
{
    public function __construct(
        public string $queue,
        public int $pending,
        public int $delayed,
        public int $reserved,
        public int $oldestSeconds,
        public bool $late,
    ) {}
}
