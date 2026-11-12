<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Src\Shared\Application\UseCases\CheckAiStatus;
use Src\Shared\Application\UseCases\CheckHealth;
use Src\Shared\Domain\AiStatus;

final readonly class HealthController
{
    public function __construct(private CheckHealth $checkHealth, private CheckAiStatus $checkAiStatus) {}

    /**
     * Report whether the application works, without internal details (RNF-14). `ia` is informative: a provider that is down or
     * unconfigured never turns the answer into a failure, because nothing else depends on it (RNF-11). A public route does not say
     * that the configuration is missing: "not configured" is shown as degraded (the operator reads it in the log).
     */
    public function __invoke(): JsonResponse
    {
        $status = $this->checkAiStatus->execute();
        $ai = ($status === AiStatus::NotConfigured ? AiStatus::Degraded : $status)->value;

        return $this->checkHealth->execute()
            ? new JsonResponse(['estado' => 'ok', 'ia' => $ai])
            : new JsonResponse(['estado' => 'fallo', 'ia' => $ai], 503);
    }
}
