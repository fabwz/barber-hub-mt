<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\UseCases;

use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Monitoring\Application\Contracts\QueueReader;
use Src\Shared\Monitoring\Application\DTOs\QueueStatus;
use Src\Shared\Monitoring\Application\MonitoringPolicy;

/** The state of the `default` and `realtime` queues, each one apart (RNF-15, RNF-09). */
final readonly class GetQueueStatus
{
    public function __construct(private Clock $clock, private QueueReader $queues, private MonitoringPolicy $policy) {}

    /**
     * Only `default` is marked late, when its oldest ready task waits longer than the threshold (NO-01 expects the notices within 60 s).
     *
     * @return list<QueueStatus>
     */
    public function execute(): array
    {
        $now = $this->clock->now();
        $statuses = [];

        foreach (MonitoringPolicy::QUEUES as $queue) {
            $counts = $this->queues->counts($queue, $now);
            $statuses[] = new QueueStatus(
                $queue,
                $counts->pending,
                $counts->delayed,
                $counts->reserved,
                $counts->oldestSeconds,
                $queue === 'default' && $counts->oldestSeconds > $this->policy->queueAlertSeconds,
            );
        }

        return $statuses;
    }
}
