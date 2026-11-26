<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\UseCases;

use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Monitoring\Application\Contracts\RequestStatsReader;
use Src\Shared\Monitoring\Application\DTOs\ErrorGroup;
use Src\Shared\Monitoring\Application\MonitoringPolicy;

/** The errors of the report window by class of exception and route (RNF-15). */
final readonly class GetErrorSummary
{
    public function __construct(private Clock $clock, private RequestStatsReader $stats, private MonitoringPolicy $policy) {}

    /** @return list<ErrorGroup> */
    public function execute(): array
    {
        return $this->stats->errorsSince($this->clock->now()->modify("-{$this->policy->reportWindowHours} hours"));
    }
}
