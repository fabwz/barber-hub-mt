<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Src\Shared\Application\UseCases\CheckHealth;

final readonly class HealthController
{
    public function __construct(private CheckHealth $checkHealth) {}

    /** Report whether the application works, without internal details (RNF-14). */
    public function __invoke(): JsonResponse
    {
        return $this->checkHealth->execute()
            ? new JsonResponse(['estado' => 'ok'])
            : new JsonResponse(['estado' => 'fallo'], 503);
    }
}
