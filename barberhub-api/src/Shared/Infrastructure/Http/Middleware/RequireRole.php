<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Src\Shared\Infrastructure\Tenancy\RequestBarbershopContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class RequireRole
{
    /**
     * Let the request through only when the role the person holds in the barbershop is one of the allowed ones; any other
     * role, or none, answers 403 "prohibido". It reads the role the "barbershop" middleware set, so it goes after it.
     * The permission matrix of every route is the work of #43.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE);

        if (! is_string($role) || ! in_array($role, $roles, true)) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
