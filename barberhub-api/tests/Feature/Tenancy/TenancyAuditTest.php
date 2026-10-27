<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Closure;
use Database\Seeders\DemoBarbershopSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\BarbershopIsolation;
use Tests\Support\Tenancy\TenancyAudit;
use Tests\Support\Tenancy\TenantResource;
use Tests\Support\Tenancy\TenantResources;
use Tests\TestCase;

/**
 * The audit behind the tenancy tests can fail: on a synthetic resource (the probe table and routes registered here) a safe
 * version has no violations and each deliberate leak has some. The real resources are registered alongside, so a probe
 * is judged on what it adds.
 */
final class TenancyAuditTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string BASE = 'api/v1/pruebas/barberias/{barberia}/';

    private string $puraNavaja;

    private string $fadeLab;

    /** Create the probe table and the two demo barbershops, and act as the owner of the first. */
    protected function setUp(): void
    {
        parent::setUp();

        BarbershopIsolation::createProbe(DB::connection('pgsql_admin'));
        // Registered after the rollback of the refresh trait, so the application's transaction no longer holds the table.
        $this->beforeApplicationDestroyed(fn () => BarbershopIsolation::dropProbe(DB::connection('pgsql_admin')));

        $this->seed(DemoBarbershopSeeder::class);
        [$puraNavaja, $fadeLab] = DemoBarbershopSeeder::BARBERSHOPS;
        $this->puraNavaja = $puraNavaja['id'];
        $this->fadeLab = $fadeLab['id'];
        Sanctum::actingAs(UserModel::query()->findOrFail($puraNavaja['owner']['id']), []);
    }

    /** The safe version, with the middleware and the policy, has no violation of either audit. */
    public function test_a_safe_resource_has_no_violations(): void
    {
        $resource = $this->registerRoutes('sondas', ['auth:sanctum', 'barbershop'], false);

        $this->assertSame([], $this->coverage([$resource]));
        $this->assertSame([], $this->isolation([$resource]));
    }

    /** A table with barbershop_id and a route with {barberia} that nobody registered are reported. */
    public function test_an_unregistered_table_and_route_are_reported(): void
    {
        $this->registerRoutes('sondas', ['auth:sanctum', 'barbershop'], false);

        $violations = $this->coverage([]);

        $this->assertContains('tenancy_probe: has barbershop_id but is not in TenantResources', $violations);
        $this->assertContains('GET '.self::BASE.'sondas: belongs to no resource of TenantResources and is not a listed public route', $violations);
    }

    /** A route registered without the middleware is reported, and a public route must use the public mode. */
    public function test_a_route_without_the_middleware_is_reported(): void
    {
        $resource = $this->registerRoutes('sondas', ['auth:sanctum'], true);

        $violations = $this->coverage([$resource]);

        $this->assertContains('GET '.self::BASE.'sondas: is without the barbershop middleware', $violations);
        $this->assertContains('DELETE '.self::BASE.'sondas/{id}: is without the barbershop middleware', $violations);
        $this->assertContains(
            'GET '.self::BASE.'sondas: is without the barbershop:public middleware',
            $this->coverage([$resource], ['GET '.self::BASE.'sondas']),
        );
    }

    /** A customer route must use the customer mode: with the plain middleware it would answer 404 to the first booking of a person. */
    public function test_a_customer_route_without_the_customer_mode_is_reported(): void
    {
        $resource = $this->registerRoutes('sondas', ['auth:sanctum', 'barbershop'], false);
        $route = 'GET '.self::BASE.'sondas';

        $violations = TenancyAudit::coverageViolations([...TenantResources::all(), $resource], DB::connection(), Route::getRoutes(), TenantResources::PUBLIC_ROUTES, [...TenantResources::CUSTOMER_ROUTES, $route]);

        $this->assertContains("{$route}: is without the barbershop:customer middleware", $violations);
    }

    /** A route that sets the barbershop of the URL without checking the person lets a member read and change the other barbershop. */
    public function test_a_route_that_trusts_the_url_is_reported(): void
    {
        $resource = $this->registerRoutes('sondas', ['auth:sanctum'], true);

        $violations = $this->isolation([$resource]);

        $this->assertContains('tenancy_probe: list with the URL of the other barbershop answered 200 instead of 404', $violations);
        $this->assertContains('tenancy_probe: show with the URL of the other barbershop answered 200 instead of 404', $violations);
        $this->assertContains('tenancy_probe: update with the URL of the other barbershop answered 200 instead of 404', $violations);
        $this->assertContains('tenancy_probe: delete with the URL of the other barbershop answered 204 instead of 404', $violations);
        $this->assertContains('tenancy_probe: the row of the other barbershop changed or disappeared', $violations);
    }

    /** Without the policy, queries that forget the filter reach the other barbershop: the listing, the row by identifier and the plain query. */
    public function test_a_table_without_its_policy_is_reported(): void
    {
        DB::connection('pgsql_admin')->statement('ALTER TABLE tenancy_probe DISABLE ROW LEVEL SECURITY');
        $resource = $this->registerRoutes('sondas', ['auth:sanctum', 'barbershop'], false);

        $violations = $this->isolation([$resource]);

        $this->assertContains('tenancy_probe: the listing of the own barbershop shows a row of the other barbershop', $violations);
        $this->assertContains('tenancy_probe: show of a row of the other barbershop answered 200 instead of 404', $violations);
        $this->assertContains('tenancy_probe: a query without a barbershop filter returned a row of another barbershop', $violations);
    }

    /** A resource whose routes do not exist is reported, so a 404 from a missing route does not pass for isolation. */
    public function test_a_resource_without_routes_is_reported(): void
    {
        $violations = $this->isolation([$this->resource('sondas')]);

        $this->assertContains('tenancy_probe: the listing of the own barbershop does not show the own row', $violations);
        $this->assertContains('tenancy_probe: show of an own row does not succeed', $violations);
    }

    /**
     * @param  list<TenantResource>  $resources
     * @param  list<string>  $publicRoutes
     * @return list<string>
     */
    private function coverage(array $resources, array $publicRoutes = []): array
    {
        // The real resources and public routes are registered too, so only what the probe adds is left to report.
        return TenancyAudit::coverageViolations(
            [...TenantResources::all(), ...$resources],
            DB::connection(),
            Route::getRoutes(),
            [...TenantResources::PUBLIC_ROUTES, ...$publicRoutes],
            TenantResources::CUSTOMER_ROUTES,
            TenantResources::BARBERSHOP_ROUTES,
        );
    }

    /**
     * @param  list<TenantResource>  $resources
     * @return list<string>
     */
    private function isolation(array $resources): array
    {
        return TenancyAudit::isolationViolations(
            $resources,
            $this->puraNavaja,
            $this->fadeLab,
            fn (string $method, string $uri, array $payload) => $this->json($method, $uri, $payload),
        );
    }

    /** The probe table as a resource served under a segment. */
    private function resource(string $segment): TenantResource
    {
        return new TenantResource(
            BarbershopIsolation::PROBE,
            fn (string $barbershopId): string => (string) DB::table(BarbershopIsolation::PROBE)->insertGetId(['barbershop_id' => $barbershopId, 'label' => 'row'], 'id'),
            self::BASE.$segment,
            [TenantResource::LIST, TenantResource::SHOW, TenantResource::UPDATE, TenantResource::DELETE],
            ['label' => 'changed'],
        );
    }

    /**
     * Run a query as a route of the probe does: in the barbershop of the URL when it trusts it, or in the context already set.
     *
     * @param  Closure(): mixed  $query
     */
    private static function scoped(bool $trustsTheUrl, string $barbershopId, Closure $query): mixed
    {
        return $trustsTheUrl ? (new BarbershopTransaction)->run($barbershopId, $query) : $query();
    }

    /**
     * Register the four routes of the probe under a segment. With $trustsTheUrl they set the barbershop of the URL
     * themselves, like a route that skips the check of the middleware; otherwise they rely on the context the middleware sets.
     *
     * @param  list<string>  $middleware
     */
    private function registerRoutes(string $segment, array $middleware, bool $trustsTheUrl): TenantResource
    {
        $scoped = fn (string $barbershopId, Closure $query): mixed => self::scoped($trustsTheUrl, $barbershopId, $query);
        $row = fn (string $id): array => (array) (DB::table(BarbershopIsolation::PROBE)->where('id', $id)->first() ?? throw new NotFoundHttpException);

        Route::prefix('api/v1/pruebas')->middleware(['api', ...$middleware])->group(function () use ($segment, $scoped, $row): void {
            $base = "barberias/{barberia}/{$segment}";

            Route::get($base, fn (string $barberia): array => [
                'data' => $scoped($barberia, fn (): array => DB::table(BarbershopIsolation::PROBE)->get(['id', 'label'])->all()),
            ])->whereUuid('barberia');
            Route::get("{$base}/{id}", fn (string $barberia, string $id): array => $scoped($barberia, fn (): array => $row($id)))
                ->whereUuid(['barberia', 'id']);
            Route::patch("{$base}/{id}", fn (string $barberia, string $id): array => $scoped($barberia, function () use ($id): array {
                DB::table(BarbershopIsolation::PROBE)->where('id', $id)->update(['label' => request()->input('label')]) > 0 || throw new NotFoundHttpException;

                return ['id' => $id];
            }))->whereUuid(['barberia', 'id']);
            Route::delete("{$base}/{id}", fn (string $barberia, string $id): mixed => $scoped($barberia, function () use ($id) {
                DB::table(BarbershopIsolation::PROBE)->where('id', $id)->delete() > 0 || throw new NotFoundHttpException;

                return response()->noContent();
            }))->whereUuid(['barberia', 'id']);
        });

        return $this->resource($segment);
    }
}
