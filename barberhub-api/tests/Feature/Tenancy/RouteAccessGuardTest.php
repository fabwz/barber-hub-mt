<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Src\Shared\Infrastructure\Http\Middleware\RequireRole;
use Src\Shared\Infrastructure\Tenancy\ScopeRequestToBarbershop;
use Tests\Support\Tenancy\AccessAudit;
use Tests\Support\Tenancy\AccessMatrix;
use Tests\Support\Tenancy\RouteRule;
use Tests\TestCase;

/**
 * A route that nobody wrote in the permission matrix fails here, so a new operation cannot reach the CI unprotected or open
 * by accident (RF-42, UP-02). No database is needed: it reads the router.
 */
final class RouteAccessGuardTest extends TestCase
{
    /** Every route of the application has a rule, every rule has a route and the middleware of each route is the one its rule demands. */
    public function test_every_route_has_a_rule_and_the_middleware_it_demands(): void
    {
        $this->assertSame([], AccessAudit::violations(Route::getRoutes(), AccessMatrix::rules()));
    }

    /** The guard sees a route nobody classified. */
    public function test_a_route_without_a_rule_is_reported(): void
    {
        $routes = new RouteCollection;
        $routes->add(Route::get('api/v1/barberias/{barberia}/secretos', fn (): string => 'x')->middleware(['auth:sanctum', 'barbershop']));

        $violations = AccessAudit::violations($routes, []);

        $this->assertSame(['GET api/v1/barberias/{barberia}/secretos: is not in the AccessMatrix, so nobody decided who may call it'], $violations);
    }

    /** The guard sees a rule whose route was removed or renamed. */
    public function test_a_rule_without_a_route_is_reported(): void
    {
        $violations = AccessAudit::violations(new RouteCollection, ['GET api/v1/fantasma' => RouteRule::public('Prueba.')]);

        $this->assertSame(['GET api/v1/fantasma: is in the AccessMatrix but no route answers it'], $violations);
    }

    /** The guard sees a route that demands less than its rule says: no session, no membership, or a wider role filter. */
    public function test_a_route_with_less_middleware_than_its_rule_is_reported(): void
    {
        $role = ScopeRequestToBarbershop::class;
        $routes = new RouteCollection;
        $routes->add(Route::post('api/v1/barberias/{barberia}/dinero', fn (): string => 'x')->middleware([$role, RequireRole::class.':owner']));
        $routes->add(Route::post('api/v1/barberias/{barberia}/mas', fn (): string => 'x')->middleware(['auth:sanctum', $role, RequireRole::class.':owner,collaborator']));
        $routes->add(Route::get('api/v1/abierta', fn (): string => 'x')->middleware('auth:sanctum'));

        $violations = AccessAudit::violations($routes, [
            'POST api/v1/barberias/{barberia}/dinero' => RouteRule::role(['owner'], AccessMatrix::OWNER_ONLY),
            'POST api/v1/barberias/{barberia}/mas' => RouteRule::role(['owner'], AccessMatrix::OWNER_ONLY),
            'GET api/v1/abierta' => RouteRule::public('Prueba.'),
        ]);

        $this->assertSame([
            'POST api/v1/barberias/{barberia}/dinero: is declared role but has no session middleware (auth:sanctum)',
            'POST api/v1/barberias/{barberia}/mas: is declared for [owner] but its role filter is [owner, collaborator]',
            'GET api/v1/abierta: is declared public and outside any barbershop but its middleware is different',
        ], $violations);
    }

    /** Every profile answers for the eight kinds of person, so the matrix test tries all of them. */
    public function test_every_profile_covers_every_actor(): void
    {
        foreach (AccessMatrix::OUTCOMES as $profile => $outcomes) {
            $this->assertCount(count(AccessMatrix::ACTORS), $outcomes, "The profile {$profile} does not answer for every actor.");
            $this->assertSame(401, $outcomes[0], "The profile {$profile} must refuse the person without a session with 401.");
        }
    }

    /** The permission matrix of docs/ lists every route of the matrix, so the document cannot fall behind the code. */
    public function test_the_documented_matrix_lists_every_route(): void
    {
        $document = (string) file_get_contents(base_path('../docs/permissions.md'));
        $missing = array_filter(array_keys(AccessMatrix::rules()), fn (string $key): bool => ! str_contains($document, "`{$key}`"));

        $this->assertSame([], array_values($missing), 'docs/permissions.md lacks these routes.');
    }
}
