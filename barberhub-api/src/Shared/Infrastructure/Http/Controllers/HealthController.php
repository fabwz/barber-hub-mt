<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Src\Shared\Application\UseCases\CheckAiStatus;
use Src\Shared\Application\UseCases\CheckHealth;
use Src\Shared\Domain\AiStatus;
use Src\Shared\Monitoring\Application\UseCases\RecordHealthCheck;
use Throwable;

final readonly class HealthController
{
    public function __construct(
        private CheckHealth $checkHealth,
        private CheckAiStatus $checkAiStatus,
        private RecordHealthCheck $recordHealthCheck,
    ) {}

    /**
     * Report whether the application works, without internal details (RNF-14). `ia` is informative: a provider that is down or
     * unconfigured never turns the answer into a failure, because nothing else depends on it (RNF-11). A public route does not say
     * that the configuration is missing: "not configured" is shown as degraded (the operator reads it in the log).
     *
     * With the monitoring on, the answer is also counted for the panel (at most one row per minute and result). Failing to count it never
     * changes the answer (RNF-15).
     */
    public function __invoke(): JsonResponse
    {
        $status = $this->checkAiStatus->execute();
        $ai = ($status === AiStatus::NotConfigured ? AiStatus::Degraded : $status)->value;
        $report = $this->checkHealth->execute();

        if (config('monitoring.enabled') === true) {
            try {
                $this->recordHealthCheck->execute($report->databaseOk, $report->queueOk, $ai);
            } catch (Throwable $e) {
                // Only the class: the message may carry the host or credentials.
                Log::warning('Monitoring: the health check was not recorded.', ['exception' => $e::class]);
            }
        }

        return $report->healthy()
            ? new JsonResponse(['estado' => 'ok', 'ia' => $ai])
            : new JsonResponse(['estado' => 'fallo', 'ia' => $ai], 503);
    }
}
