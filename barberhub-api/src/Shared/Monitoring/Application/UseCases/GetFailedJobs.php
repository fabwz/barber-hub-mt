<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\UseCases;

use Src\Shared\Monitoring\Application\Contracts\FailedJobsReader;
use Src\Shared\Monitoring\Application\DTOs\FailedJobsReport;

/** The tasks that used up their attempts, grouped and recent, without payload or message (RNF-09, RNF-15). */
final readonly class GetFailedJobs
{
    private const int GROUPS = 30;

    private const int RECENT = 20;

    public function __construct(private FailedJobsReader $failed) {}

    public function execute(): FailedJobsReport
    {
        return new FailedJobsReport($this->failed->groups(self::GROUPS), $this->failed->recent(self::RECENT));
    }
}
