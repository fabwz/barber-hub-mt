<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\TenancyAudit;
use Tests\Support\Tenancy\TenantResources;
use Tests\TestCase;

/** A table or a route of a barbershop that nobody registered fails here, so the isolation test cannot skip a resource (RNF-06). */
final class TenantResourceCoverageTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** Every table with barbershop_id and every route with {barberia} is in the registry or is a documented exception. */
    public function test_every_table_and_route_of_a_barbershop_is_registered(): void
    {
        $this->assertSame([], TenancyAudit::coverageViolations(
            TenantResources::all(),
            DB::connection(),
            Route::getRoutes(),
            TenantResources::PUBLIC_ROUTES,
            TenantResources::CUSTOMER_ROUTES,
            TenantResources::BARBERSHOP_ROUTES,
        ));
    }
}
