<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\Contracts;

use Src\Shared\Monitoring\Application\DTOs\FailedJob;
use Src\Shared\Monitoring\Application\DTOs\FailedJobGroup;

/** Reads the tasks that used up their attempts. The payload and the message of the exception never leave the adapter. */
interface FailedJobsReader
{
    /**
     * The failed tasks grouped by job and queue, the latest failure first.
     *
     * @return list<FailedJobGroup>
     */
    public function groups(int $limit): array;

    /**
     * The most recent failed tasks.
     *
     * @return list<FailedJob>
     */
    public function recent(int $limit): array;
}
