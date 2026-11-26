<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Monitoring\Application\Contracts\HealthChecksReader;
use Src\Shared\Monitoring\Application\DTOs\HealthStatus;
use Src\Shared\Monitoring\Application\MonitoringPolicy;

/** The health route as the panel shows it: the latest answer, the recent failures, the availability and whether anybody is asking (RNF-15, RNF-04). */
final readonly class GetHealthStatus
{
    private const int FAILURES = 10;

    public function __construct(private Clock $clock, private HealthChecksReader $checks, private MonitoringPolicy $policy) {}

    public function execute(): HealthStatus
    {
        $now = $this->clock->now();
        $last = $this->checks->latest();
        $totals = $this->checks->totalsSince($now->modify("-{$this->policy->availabilityWindowDays} days"));
        $stale = $last === null || new DateTimeImmutable($last->at) < $now->modify("-{$this->policy->staleAfterMinutes} minutes");

        return new HealthStatus(
            $last,
            $stale,
            $this->checks->failuresSince($now->modify("-{$this->policy->availabilityWindowDays} days"), self::FAILURES),
            $totals->total === 0 ? null : round($totals->healthy * 100 / $totals->total, 2),
            $totals->total,
        );
    }
}
