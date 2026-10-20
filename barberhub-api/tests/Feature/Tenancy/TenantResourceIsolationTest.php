<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Database\Seeders\DemoBarbershopSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\TenancyAudit;
use Tests\Support\Tenancy\TenantResources;
use Tests\TestCase;

/** Every registered resource, tried by the owner of Pura Navaja against Fade Lab (RF-44, MT-01 to MT-06, UP-06). */
final class TenantResourceIsolationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** Nothing in any resource of one barbershop is shown, changed or deleted from the other, whatever identifier the URL carries. */
    public function test_no_resource_crosses_to_the_other_barbershop(): void
    {
        $this->seed(DemoBarbershopSeeder::class);
        [$puraNavaja, $fadeLab] = DemoBarbershopSeeder::BARBERSHOPS;

        $this->assertSame(config('database.connections.pgsql.username'), DB::scalar('select current_user'));
        Sanctum::actingAs(UserModel::query()->findOrFail($puraNavaja['owner']['id']), []);

        $this->assertSame([], TenancyAudit::isolationViolations(
            TenantResources::all(),
            $puraNavaja['id'],
            $fadeLab['id'],
            fn (string $method, string $uri, array $payload) => $this->json($method, $uri, $payload),
        ));
    }
}
