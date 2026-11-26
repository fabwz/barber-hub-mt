<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\DTOs;

/** The failed tasks grouped by job and queue, and the most recent ones. */
final readonly class FailedJobsReport
{
    /**
     * @param  list<FailedJobGroup>  $groups
     * @param  list<FailedJob>  $recent
     */
    public function __construct(
        public array $groups,
        public array $recent,
    ) {}
}
