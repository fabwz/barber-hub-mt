<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Src\Identity\Users\Infrastructure\Http\Middleware\RequireChangedPassword;
use Src\Identity\Users\Infrastructure\Http\Middleware\RequirePlatformOperator;
use Src\Shared\Infrastructure\Http\Middleware\AssignRequestId;
use Src\Shared\Infrastructure\Http\Middleware\EncodeJsonAsUnicode;
use Src\Shared\Infrastructure\Http\Middleware\RequireRole;
use Src\Shared\Infrastructure\Http\Problem\ProblemDetailsRenderer;
use Src\Shared\Infrastructure\Tenancy\ScopeRequestToBarbershop;
use Src\Shared\Monitoring\Infrastructure\Http\Middleware\RecordRequestStats;
use Src\Shared\Monitoring\Infrastructure\Http\Middleware\RememberRequestException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        then: function (): void {
            // The health route is public and lives outside /api/v1, with no middleware group.
            require __DIR__.'/../src/Shared/Infrastructure/Http/health.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(EncodeJsonAsUnicode::class);
        $middleware->append(RecordRequestStats::class);
        $middleware->trimStrings(except: ['contrasena', 'contrasena_actual', 'contrasena_nueva']);
        $middleware->appendToGroup('api', RequireChangedPassword::class);
        $middleware->redirectGuestsTo(null);
        $middleware->alias([
            'barbershop' => ScopeRequestToBarbershop::class,
            'role' => RequireRole::class,
            'platform.operator' => RequirePlatformOperator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // First, so it takes note of the class of the exception for the monitoring and passes on (it returns null).
        $exceptions->render(new RememberRequestException);
        $exceptions->render(new ProblemDetailsRenderer);
    })->create();
