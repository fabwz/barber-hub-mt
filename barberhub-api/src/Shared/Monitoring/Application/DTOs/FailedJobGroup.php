<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** The failed tasks of one job in one queue. It carries the NAME of the job and the CLASS of its latest exception, never the payload or the message. */
final readonly class FailedJobGroup
{
    public function __construct(
        public string $job,
        public string $queue,
        public int $failures,
        public string $lastFailedAt,
        public string $exceptionClass,
    ) {}
}
