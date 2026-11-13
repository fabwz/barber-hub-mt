<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\AccessMatrix;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\TestCase;

/**
 * The permission matrix, route by route (RF-42, UP-01 to UP-07, UP-09, CO-04, CL-04): each protected route is tried as the eight
 * kinds of person of AccessMatrix::ACTORS and must answer what its profile says. Every refusal is also checked for what it
 * gives away: a person of another barbershop or of another person's resource gets the answer of a resource that does not exist,
 * no refusal names anything of the resource, and nothing changes in the database.
 */
final class AccessMatrixTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;

    /**
     * The protected routes of the matrix, one case each.
     *
     * @return array<string, array{string}>
     */
    public static function protectedRoutes(): array
    {
        $cases = [];

        foreach (AccessMatrix::rules() as $key => $rule) {
            if ($rule->outcomes !== null) {
                $cases[$key] = [$key];
            }
        }

        return $cases;
    }

    #[DataProvider('protectedRoutes')]
    public function test_each_person_gets_the_answer_the_matrix_says(string $key): void
    {
        $this->buildAccessScenario();
        [$method, $template] = explode(' ', $key, 2);
        $outcomes = AccessMatrix::OUTCOMES[AccessMatrix::rules()[$key]->outcomes ?? ''];
        $payload = $this->payloadOf($key);
        $secrets = $this->secrets();
        $before = $this->fingerprint();
        $refusals = [];

        // First the refused ones: nothing they do may change a row, whatever the others do afterwards.
        foreach (AccessMatrix::ACTORS as $position => $actor) {
            $outcome = $outcomes[$position];

            if (! is_int($outcome)) {
                continue;
            }

            $response = $this->sendAs($actor, $method, $this->addressOf($template, $this->puraNavaja, $this->resources), $payload);
            $this->assertSame($outcome, $response->getStatusCode(), "{$key} as {$actor} must answer {$outcome}");

            $problem = $this->problemOf($response);
            $this->assertArrayNotHasKey('data', $problem, "{$key} as {$actor}: a refusal carries data");

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, (string) json_encode($problem, JSON_UNESCAPED_UNICODE), "{$key} as {$actor}: the refusal gives away {$secret}");
            }

            if ($outcome === 404) {
                $this->assertSame($this->notFoundFor($actor, $method, $template, $payload), $problem, "{$key} as {$actor}: the 404 differs from the one of a resource that does not exist");
            }

            if ($outcome === 403) {
                $refusals[$actor] = $problem;
            }
        }

        $this->assertLessThanOrEqual(1, count(array_unique(array_map(fn (array $problem): string => (string) json_encode($problem), $refusals))), "{$key}: the 403 of each person is different");
        $this->assertSame($before, $this->fingerprint(), "{$key}: a refused request changed the database");

        foreach (AccessMatrix::ACTORS as $position => $actor) {
            $outcome = $outcomes[$position];

            if (is_int($outcome)) {
                continue;
            }

            $response = $this->sendAs($actor, $method, $this->addressOf($template, $this->puraNavaja, $this->resources), $payload);
            $status = $response->getStatusCode();

            if ($outcome === AccessMatrix::EMPTY) {
                $response->assertOk();
                $this->assertSame([], $response->json('data'), "{$key} as {$actor} must see an empty list");

                continue;
            }

            if ($method === 'GET') {
                $this->assertSame(200, $status, "{$key} as {$actor} must read it");
            } else {
                $this->assertNotContains($status, [401, 403, 404], "{$key} as {$actor} must get through the authorization");
                $this->assertLessThan(500, $status, "{$key} as {$actor} failed on the server");
            }
        }
    }

    /**
     * What a person of another barbershop gets is what a barbershop that does not exist gives; what a person of this one gets
     * for a resource of another person is what a missing resource gives.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function notFoundFor(string $actor, string $method, string $template, array $payload): array
    {
        $outsider = str_starts_with($actor, 'foreign_');
        $uri = $outsider
            ? $this->addressOf($template, (string) Str::uuid7(), $this->resources)
            : $this->addressOf($template, $this->puraNavaja, $this->missingResources());
        $response = $this->sendAs($actor, $method, $uri, $payload);

        $this->assertSame(404, $response->getStatusCode(), "The baseline of {$actor} for {$template} is not a 404");

        return $this->problemOf($response);
    }

    /**
     * What a refusal must never contain: names, contact data and the identifiers of the resources.
     *
     * @return list<string>
     */
    private function secrets(): array
    {
        return ['Ana Rojas', 'Luis Mora', 'Bea Mora', 'Degradado', 'Daniela', 'Kevin', 'Mauricio', '@barberhub.test', ...array_values($this->resources), ...array_values($this->foreignResources)];
    }
}
