<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application;

/** The numbers the monitoring follows: how long it keeps what it records, the windows the panel reads and when it raises an alert. */
final readonly class MonitoringPolicy
{
    /** The queues the panel shows, each one apart. */
    public const array QUEUES = ['default', 'realtime'];

    public function __construct(
        public int $retentionDays = 14,
        public int $reportWindowHours = 24,
        public int $availabilityWindowDays = 14,
        public int $staleAfterMinutes = 10,
        public int $queueAlertSeconds = 60,
    ) {}
}
