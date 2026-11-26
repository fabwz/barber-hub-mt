<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Shared\Monitoring\Application\Contracts\HealthChecksReader;
use Src\Shared\Monitoring\Application\Contracts\QueueReader;
use Src\Shared\Monitoring\Application\DTOs\HealthCheckEntry;
use Src\Shared\Monitoring\Application\DTOs\HealthTotals;
use Src\Shared\Monitoring\Application\DTOs\QueueCounts;
use Src\Shared\Monitoring\Application\MonitoringPolicy;
use Src\Shared\Monitoring\Application\UseCases\GetHealthStatus;
use Src\Shared\Monitoring\Application\UseCases\GetQueueStatus;
use Tests\Support\Shared\MutableClock;

/** The two readings that decide something, not only copy rows: when a queue is late, and when nobody is asking for the health route (RNF-15). */
final class ReadUseCasesTest extends TestCase
{
    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
    }

    /** Only `default` is marked late, and only above the threshold: 60 s still counts as on time. */
    public function test_only_the_default_queue_is_late_and_only_above_the_threshold(): void
    {
        $queues = new class implements QueueReader
        {
            public function counts(string $queue, DateTimeImmutable $now): QueueCounts
            {
                return new QueueCounts(2, 1, 0, $queue === 'default' ? 61 : 600);
            }
        };

        $statuses = (new GetQueueStatus($this->clock, $queues, new MonitoringPolicy))->execute();

        $this->assertSame(['default', 'realtime'], array_map(fn ($status): string => $status->queue, $statuses));
        $this->assertSame([true, false], array_map(fn ($status): bool => $status->late, $statuses));

        $onTime = new class implements QueueReader
        {
            public function counts(string $queue, DateTimeImmutable $now): QueueCounts
            {
                return new QueueCounts(1, 0, 0, 60);
            }
        };

        $this->assertFalse((new GetQueueStatus($this->clock, $onTime, new MonitoringPolicy))->execute()[0]->late);
    }

    /** With no answer ever recorded the route is "stale"; a recent answer is not, an old one is; the availability is the share of 200 answers. */
    public function test_the_health_status_says_when_nobody_is_asking_and_the_availability(): void
    {
        $at = static fn (string $time): HealthCheckEntry => new HealthCheckEntry($time, true, true, 3);
        $policy = new MonitoringPolicy(staleAfterMinutes: 10);

        $none = $this->checks(null, new HealthTotals(0, 0));
        $recent = $this->checks($at('2030-03-01 07:55:00-06'), new HealthTotals(1000, 999));
        $old = $this->checks($at('2030-03-01 07:49:59-06'), new HealthTotals(3, 2));

        $status = (new GetHealthStatus($this->clock, $none, $policy))->execute();
        $this->assertTrue($status->stale);
        $this->assertNull($status->availabilityPercent);

        $status = (new GetHealthStatus($this->clock, $recent, $policy))->execute();
        $this->assertFalse($status->stale);
        $this->assertSame(99.9, $status->availabilityPercent);
        $this->assertSame(1000, $status->totalChecks);

        $status = (new GetHealthStatus($this->clock, $old, $policy))->execute();
        $this->assertTrue($status->stale);
        $this->assertSame(66.67, $status->availabilityPercent);
    }

    private function checks(?HealthCheckEntry $latest, HealthTotals $totals): HealthChecksReader
    {
        return new class($latest, $totals) implements HealthChecksReader
        {
            public function __construct(private readonly ?HealthCheckEntry $latest, private readonly HealthTotals $totals) {}

            public function latest(): ?HealthCheckEntry
            {
                return $this->latest;
            }

            public function failuresSince(DateTimeImmutable $since, int $limit): array
            {
                return [];
            }

            public function totalsSince(DateTimeImmutable $since): HealthTotals
            {
                return $this->totals;
            }
        };
    }
}
