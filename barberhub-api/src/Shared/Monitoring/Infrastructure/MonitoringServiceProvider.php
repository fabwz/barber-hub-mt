<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Shared\Monitoring\Application\Contracts\FailedJobsReader;
use Src\Shared\Monitoring\Application\Contracts\HealthChecksReader;
use Src\Shared\Monitoring\Application\Contracts\QueueReader;
use Src\Shared\Monitoring\Application\Contracts\RequestStatsReader;
use Src\Shared\Monitoring\Application\MonitoringPolicy;
use Src\Shared\Monitoring\Domain\Contracts\HealthChecksWriter;
use Src\Shared\Monitoring\Domain\Contracts\MonitoringRetention;
use Src\Shared\Monitoring\Domain\Contracts\RequestStatsWriter;
use Src\Shared\Monitoring\Infrastructure\Persistence\DatabaseFailedJobsReader;
use Src\Shared\Monitoring\Infrastructure\Persistence\DatabaseHealthChecksReader;
use Src\Shared\Monitoring\Infrastructure\Persistence\DatabaseHealthChecksWriter;
use Src\Shared\Monitoring\Infrastructure\Persistence\DatabaseMonitoringRetention;
use Src\Shared\Monitoring\Infrastructure\Persistence\DatabaseQueueReader;
use Src\Shared\Monitoring\Infrastructure\Persistence\DatabaseRequestStatsReader;
use Src\Shared\Monitoring\Infrastructure\Persistence\DatabaseRequestStatsWriter;

final class MonitoringServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        RequestStatsWriter::class => DatabaseRequestStatsWriter::class,
        HealthChecksWriter::class => DatabaseHealthChecksWriter::class,
        MonitoringRetention::class => DatabaseMonitoringRetention::class,
        RequestStatsReader::class => DatabaseRequestStatsReader::class,
        QueueReader::class => DatabaseQueueReader::class,
        FailedJobsReader::class => DatabaseFailedJobsReader::class,
        HealthChecksReader::class => DatabaseHealthChecksReader::class,
    ];

    /** The thresholds come from `config/monitoring.php`, read when the policy is first needed. */
    public function register(): void
    {
        $this->app->bind(MonitoringPolicy::class, fn (): MonitoringPolicy => new MonitoringPolicy(
            staleAfterMinutes: config()->integer('monitoring.stale_after_minutes'),
            queueAlertSeconds: config()->integer('monitoring.queue_alert_seconds'),
        ));
    }

    /** Load the route of the panel and its view. */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Http/views', 'monitoring');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
