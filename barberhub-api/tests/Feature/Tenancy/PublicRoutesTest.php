<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\AccessMatrix;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\Support\Tenancy\RouteRule;
use Tests\TestCase;

/**
 * The routes open on purpose (the ones the AccessMatrix lists as public) answer without a session and give away nothing of any
 * person: no name, no contact data and no identifier of a reservation or a payment. The ones that change something refuse what
 * they cannot verify (RF-42, RF-22, MT-09, SE-03).
 */
final class PublicRoutesTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;

    /**
     * Each public route with the call that tries it and the statuses it may answer without a session. A route of the matrix that
     * is not here fails the first test below, so a new public route must say how it is tried.
     *
     * @return array<string, array{string, string, array<string, mixed>, list<int>}>
     */
    public static function publicRoutes(): array
    {
        return [
            'GET api/v1' => ['GET', 'api/v1', [], [200]],
            'POST api/v1/cuentas' => ['POST', 'api/v1/cuentas', [], [422]],
            'POST api/v1/tokens' => ['POST', 'api/v1/tokens', [], [422]],
            'GET api/v1/enlaces/{enlace}' => ['GET', 'api/v1/enlaces/pura-navaja', [], [200]],
            'GET api/v1/barberias/{barberia}/servicios' => ['GET', 'api/v1/barberias/{barberia}/servicios', [], [200]],
            'GET api/v1/barberias/{barberia}/disponibilidad' => ['GET', 'api/v1/barberias/{barberia}/disponibilidad', [], [200, 422]],
            'POST api/v1/pagos/avisos/onvo' => ['POST', 'api/v1/pagos/avisos/onvo', [], [400, 401, 403, 422]],
            // PayPal answers 503 while its credentials are not configured, as in the tests; any of these refuses what cannot be verified.
            'POST api/v1/pagos/avisos/paypal' => ['POST', 'api/v1/pagos/avisos/paypal', [], [400, 401, 403, 422, 503]],
            'GET api/v1/pagos/retorno' => ['GET', 'api/v1/pagos/retorno', [], [200]],
            'GET salud' => ['GET', 'salud', [], [200]],
            'GET docs/api' => ['GET', 'docs/api', [], [200]],
            'GET docs/api.json' => ['GET', 'docs/api.json', [], [200]],
            'GET storage/{path}' => ['GET', 'storage/cualquiera.txt', [], [403]],
            'PUT storage/{path}' => ['PUT', 'storage/cualquiera.txt', [], [403]],
        ];
    }

    /** Every public route of the matrix has a call here, and every call here is a public route of the matrix. */
    public function test_every_public_route_is_tried(): void
    {
        $public = array_keys(array_filter(AccessMatrix::rules(), fn (RouteRule $rule): bool => $rule->kind === RouteRule::PUBLIC && ! $rule->conditional));
        $tried = array_keys(array_filter(self::publicRoutes(), fn (array $call, string $key): bool => AccessMatrix::rules()[$key]->conditional === false, ARRAY_FILTER_USE_BOTH));

        $this->assertEqualsCanonicalizing($public, $tried);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $statuses
     */
    #[DataProvider('publicRoutes')]
    public function test_a_public_route_answers_without_a_session_and_gives_nothing_private_away(string $method, string $template, array $payload, array $statuses): void
    {
        $this->buildAccessScenario();
        $response = $this->sendAs('anonymous', $method, $this->addressOf($template, $this->puraNavaja, []), $payload);

        $this->assertContains($response->getStatusCode(), $statuses, "{$method} {$template} answered {$response->getStatusCode()}");

        $content = (string) $response->getContent();

        foreach (['Ana Rojas', 'Luis Mora', 'Bea Mora', 'Mauricio Dueño', 'Dueño de Fade Lab', '@barberhub.test', $this->resources['reserva'], $this->resources['pago'], $this->foreignResources['reserva'], $this->foreignResources['pago']] as $secret) {
            $this->assertStringNotContainsString($secret, $content, "{$template} gives away {$secret}");
        }
    }

    /** The public routes of the framework and the documentation exist only if the matrix says so: a conditional one that is registered must be listed. */
    public function test_the_routes_outside_the_api_are_all_listed(): void
    {
        $outside = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                $outside[] = AccessMatrix::keyOf(implode('|', $route->methods()), $route->uri());
            }
        }

        $this->assertSame([], array_values(array_diff($outside, array_keys(AccessMatrix::rules()))));
    }
}
