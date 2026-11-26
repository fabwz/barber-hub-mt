<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** One failed task: its identifier, the name of the job, the queue, when it failed and the class of its exception. No payload, no message. */
final readonly class FailedJob
{
    public function __construct(
        public string $uuid,
        public string $job,
        public string $queue,
        public string $failedAt,
        public string $exceptionClass,
    ) {}
}
