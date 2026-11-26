<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Monitoring\Application\DTOs\QueueCounts;

/** Reads the tasks waiting in a queue. */
interface QueueReader
{
    /** What the queue holds at the instant. Only counts and ages: nothing of the tasks themselves. */
    public function counts(string $queue, DateTimeImmutable $now): QueueCounts;
}
