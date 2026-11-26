<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Src\Shared\Monitoring\Application\MonitoringPolicy;
use Src\Shared\Monitoring\Application\UseCases\GetErrorSummary;
use Src\Shared\Monitoring\Application\UseCases\GetFailedJobs;
use Src\Shared\Monitoring\Application\UseCases\GetHealthStatus;
use Src\Shared\Monitoring\Application\UseCases\GetQueueStatus;
use Src\Shared\Monitoring\Application\UseCases\GetResponseTimes;

/** The read-only monitoring panel (RNF-15): one HTML page, without JavaScript, with the five answers of the use cases. */
final readonly class MonitoringPanelController
{
    public function __construct(
        private GetErrorSummary $errors,
        private GetResponseTimes $timings,
        private GetQueueStatus $queues,
        private GetFailedJobs $failedJobs,
        private GetHealthStatus $health,
        private MonitoringPolicy $policy,
    ) {}

    /** Render the panel. It is never cached or indexed. */
    public function __invoke(): Response
    {
        $timezone = config()->string('app.timezone');

        return response()
            ->view('monitoring::panel', [
                'errorGroups' => $this->errors->execute(),
                'timings' => $this->timings->execute(),
                'queues' => $this->queues->execute(),
                'failed' => $this->failedJobs->execute(),
                'health' => $this->health->execute(),
                'policy' => $this->policy,
                'format' => fn (string $instant): string => CarbonImmutable::parse($instant, $timezone)->setTimezone($timezone)->format('d/m/Y H:i:s'),
            ])
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
