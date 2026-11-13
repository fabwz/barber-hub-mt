<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Src\Shared\Infrastructure\Http\Middleware\RequireRole;
use Src\Shared\Infrastructure\Tenancy\ScopeRequestToBarbershop;

/**
 * The check behind the guard test (RF-42). It returns the list of violations instead of asserting, so a test can also feed
 * it a route nobody wrote down, or one that demands less than its rule says, and see that it reports them.
 */
final class AccessAudit
{
    /**
     * What is wrong between the routes of the application and the matrix: a route without a rule, a rule without a route, and
     * a route whose middleware is not what its rule says (a session, the mode of the barbershop and the roles).
     *
     * @param  array<string, RouteRule>  $rules
     * @return list<string>
     */
    public static function violations(RouteCollectionInterface $routes, array $rules): array
    {
        $violations = [];
        $found = [];

        foreach ($routes->getRoutes() as $route) {
            $key = AccessMatrix::keyOf(implode('|', $route->methods()), $route->uri());
            $found[$key] = true;
            $rule = $rules[$key] ?? null;

            if ($rule === null) {
                $violations[] = "{$key}: is not in the AccessMatrix, so nobody decided who may call it";

                continue;
            }

            $violations = [...$violations, ...self::middlewareViolations($key, $route, $rule)];
        }

        foreach ($rules as $key => $rule) {
            if (! isset($found[$key]) && ! $rule->conditional) {
                $violations[] = "{$key}: is in the AccessMatrix but no route answers it";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function middlewareViolations(string $key, Route $route, RouteRule $rule): array
    {
        // The aliases of the route ("auth:sanctum", "barbershop", "role:owner") and its groups, as the classes they stand for.
        $middleware = app(Router::class)->gatherRouteMiddleware($route);
        $authenticated = in_array(Authenticate::class.':sanctum', $middleware, true);
        $scopeArguments = self::argumentsOf($middleware, ScopeRequestToBarbershop::class);
        $scopeMode = $scopeArguments === null ? null : ($scopeArguments[0] ?? ScopeRequestToBarbershop::FROM_MEMBERSHIP);
        $roles = self::argumentsOf($middleware, RequireRole::class);
        $violations = [];

        if ($rule->kind === RouteRule::PUBLIC) {
            $expectedScope = $rule->scoped ? 'public' : null;

            if ($authenticated || $roles !== null || $scopeMode !== $expectedScope) {
                $violations[] = "{$key}: is declared public ".($rule->scoped ? 'with the barbershop of the URL' : 'and outside any barbershop').' but its middleware is different';
            }

            if ($rule->note === '') {
                $violations[] = "{$key}: is public without a reason";
            }

            return $violations;
        }

        if (! $authenticated) {
            $violations[] = "{$key}: is declared {$rule->kind} but has no session middleware (auth:sanctum)";
        }

        if ($rule->kind === RouteRule::AUTHENTICATED) {
            if ($scopeMode !== null || $roles !== null) {
                $violations[] = "{$key}: is declared authenticated only but has the barbershop or role middleware";
            }

            return $violations;
        }

        $expectedMode = $rule->kind === RouteRule::FIRST_USE ? ScopeRequestToBarbershop::FOR_CUSTOMER : ScopeRequestToBarbershop::FROM_MEMBERSHIP;

        if ($scopeMode !== $expectedMode) {
            $violations[] = "{$key}: is declared {$rule->kind} and must pass through the barbershop middleware in mode {$expectedMode}";
        }

        // A route open to the three roles needs no role filter: the barbershop middleware already refuses who has none.
        $expectedRoles = $rule->kind === RouteRule::FIRST_USE || count($rule->roles) === 3 ? null : $rule->roles;

        if ($expectedRoles === null && $roles !== null) {
            $violations[] = "{$key}: has a role filter its rule does not declare";
        }

        if ($expectedRoles !== null && ($roles === null || self::sorted($roles) !== self::sorted($expectedRoles))) {
            $violations[] = "{$key}: is declared for [".implode(', ', $expectedRoles).'] but its role filter is ['.implode(', ', $roles ?? []).']';
        }

        if ($rule->outcomes === null || ! isset(AccessMatrix::OUTCOMES[$rule->outcomes])) {
            $violations[] = "{$key}: has no profile of outcomes for the role matrix test";
        }

        if ($rule->kind === RouteRule::OWNED && $rule->note === '') {
            $violations[] = "{$key}: is declared owned without saying which resource belongs to whom";
        }

        return $violations;
    }

    /**
     * The arguments a middleware was given in the route, or null when the route does not have it.
     *
     * @param  array<int, mixed>  $middleware
     * @return list<string>|null
     */
    private static function argumentsOf(array $middleware, string $class): ?array
    {
        foreach ($middleware as $entry) {
            if (! is_string($entry) || ($entry !== $class && ! str_starts_with($entry, $class.':'))) {
                continue;
            }

            return $entry === $class ? [] : explode(',', substr($entry, strlen($class) + 1));
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
