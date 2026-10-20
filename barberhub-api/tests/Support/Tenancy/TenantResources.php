<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every resource that belongs to a barbershop. The issue that creates a table with barbershop_id or a route with
 * {barberia} adds its entry here (docs/database.md, "Cómo agregar una tabla de una barbería"); the coverage test fails
 * until it does, and the isolation test then tries the resource against a second barbershop.
 */
final class TenantResources
{
    /**
     * Routes with {barberia} that are public on purpose, written "METHOD uri" as the router lists them. They must use
     * the "barbershop:public" middleware and expose only public data.
     *
     * @var list<string>
     */
    public const array PUBLIC_ROUTES = [
        'GET api/v1/barberias/{barberia}/servicios',
    ];

    /**
     * The resources to cover.
     *
     * @return list<TenantResource>
     */
    public static function all(): array
    {
        return [
            // The services the owner administers (#45); the public list is above. A service is never deleted (SV-04).
            new TenantResource(
                'services',
                function (string $barbershopId): string {
                    $id = (string) Str::uuid7();
                    DB::table('services')->insert([
                        'id' => $id,
                        'barbershop_id' => $barbershopId,
                        'name' => 'Corte clásico',
                        'duration_minutes' => 30,
                        'price' => 7000,
                    ]);

                    return $id;
                },
                'api/v1/barberias/{barberia}/administracion/servicios',
                [TenantResource::LIST, TenantResource::UPDATE],
                ['nombre' => 'Corte moderno'],
            ),
        ];
    }
}
