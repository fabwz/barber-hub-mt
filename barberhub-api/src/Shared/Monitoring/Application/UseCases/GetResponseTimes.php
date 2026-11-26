<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\UseCases;

use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Monitoring\Application\Contracts\RequestStatsReader;
use Src\Shared\Monitoring\Application\DTOs\RouteTiming;
use Src\Shared\Monitoring\Application\MonitoringPolicy;

/** The response times of the report window by route (RNF-15). */
final readonly class GetResponseTimes
{
    public function __construct(private Clock $clock, private RequestStatsReader $stats, private MonitoringPolicy $policy) {}

    /** @return list<RouteTiming> */
    public function execute(): array
    {
        return $this->stats->timingsSince($this->clock->now()->modify("-{$this->policy->reportWindowHours} hours"));
    }
}
