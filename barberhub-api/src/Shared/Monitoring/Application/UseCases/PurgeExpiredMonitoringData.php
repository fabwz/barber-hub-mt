<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\UseCases;

use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Monitoring\Application\MonitoringPolicy;
use Src\Shared\Monitoring\Domain\Contracts\MonitoringRetention;

/** Deletes what the monitoring recorded before its retention (14 days, the window of RNF-04). Today a request calls it; a scheduler can replace that call (#117). */
final readonly class PurgeExpiredMonitoringData
{
    public function __construct(private Clock $clock, private MonitoringRetention $retention, private MonitoringPolicy $policy) {}

    /** Ask the retention to purge what is older than the policy allows. The port decides how often it really deletes. */
    public function execute(): void
    {
        $this->retention->purgeBefore($this->clock->now()->modify("-{$this->policy->retentionDays} days"));
    }
}
