<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Src\Identity\Users\Infrastructure\Http\Middleware\RequireChangedPassword;
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
        // Behind the reverse proxy of the deployment (deploy/Caddyfile) the request arrives from the Docker network: only the proxies
        // listed in TRUSTED_PROXIES (comma-separated IPs or CIDR blocks) may tell the scheme, host and client address.
        // The default is the private range Docker draws its networks from; an empty value trusts none; "*" trusts any (never in production).
        $middleware->trustProxies(
            at: (string) env('TRUSTED_PROXIES', '172.16.0.0/12'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(EncodeJsonAsUnicode::class);
        $middleware->append(RecordRequestStats::class);
        $middleware->trimStrings(except: ['contrasena', 'contrasena_actual', 'contrasena_nueva']);
        $middleware->appendToGroup('api', RequireChangedPassword::class);
        $middleware->redirectGuestsTo(null);
        $middleware->alias([
            'barbershop' => ScopeRequestToBarbershop::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // First, so it takes note of the class of the exception for the monitoring and passes on (it returns null).
        $exceptions->render(new RememberRequestException);
        $exceptions->render(new ProblemDetailsRenderer);
    })->create();
