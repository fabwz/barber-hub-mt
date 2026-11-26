<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Protects the monitoring panel with HTTP Basic authentication (ADR 0013): it is a route of the PLATFORM, not of a barbershop, so a role in a
 * barbershop opens nothing here and Sanctum is not used. It fails closed: with the switch off, or without the user or the bcrypt hash of its
 * password configured, the route does not exist (404, the same answer as any unknown route). Wrong credentials answer 401, and after the
 * configured number of failed attempts from one address even the right password answers 429 until the lockout ends.
 */
final readonly class RequireMonitoringCredentials
{
    /** Check the credentials of the request. */
    public function handle(Request $request, Closure $next): Response
    {
        $user = config('monitoring.user');
        $hash = config('monitoring.password_hash');

        if (config('monitoring.enabled') !== true || ! is_string($user) || $user === '' || ! is_string($hash) || $hash === '') {
            throw new NotFoundHttpException;
        }

        // The first request of a browser carries no credentials by design: it is asked for them and does not count as an attempt.
        if ($request->getUser() === null) {
            return $this->challenge();
        }

        $key = 'monitoring-sign-in:'.hash('sha256', (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, config()->integer('monitoring.max_attempts'))) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        // Both comparisons always run, so the time does not tell which one failed.
        $passwordMatches = password_verify((string) $request->getPassword(), $hash);
        $userMatches = hash_equals($user, (string) $request->getUser());

        if ($passwordMatches && $userMatches) {
            RateLimiter::clear($key);

            return $next($request);
        }

        RateLimiter::hit($key, config()->integer('monitoring.lockout_seconds'));

        return $this->challenge();
    }

    /** Ask the browser for credentials, with an empty body. */
    private function challenge(): Response
    {
        return new Response('', Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Basic realm="BarberHub", charset="UTF-8"']);
    }
}
