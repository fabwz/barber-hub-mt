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
            // The weekly schedule the owner administers (#47). Each row takes the next half hour of Monday, because two slots of a barbershop never overlap.
            new TenantResource(
                'opening_hours',
                function (string $barbershopId): string {
                    static $hour = 6;
                    $id = (string) Str::uuid7();
                    DB::table('opening_hours')->insert([
                        'id' => $id,
                        'barbershop_id' => $barbershopId,
                        'day_of_week' => 1,
                        'opens_at' => sprintf('%02d:00', $hour),
                        'closes_at' => sprintf('%02d:30', $hour++),
                    ]);

                    return $id;
                },
                'api/v1/barberias/{barberia}/administracion/horario',
                [TenantResource::LIST, TenantResource::DELETE],
            ),
            // The closures the owner administers (#47). Each row takes the next day, because a barbershop closes a date once.
            new TenantResource(
                'closures',
                function (string $barbershopId): string {
                    static $day = 1;
                    $id = (string) Str::uuid7();
                    DB::table('closures')->insert([
                        'id' => $id,
                        'barbershop_id' => $barbershopId,
                        'closed_on' => sprintf('2030-01-%02d', $day++),
                        'reason' => 'Feriado',
                    ]);

                    return $id;
                },
                'api/v1/barberias/{barberia}/administracion/cierres',
                [TenantResource::LIST, TenantResource::DELETE],
            ),
            // The collaborators the owner administers (#48). A profile belongs to an account, so each row brings its own.
            new TenantResource(
                'collaborators',
                fn (string $barbershopId): string => self::createCollaborator($barbershopId),
                'api/v1/barberias/{barberia}/administracion/colaboradores',
                [TenantResource::LIST, TenantResource::SHOW, TenantResource::UPDATE],
                ['activo' => false],
            ),
            // The children of a collaborator are served inside the collaborator, so they have no route of their own: the
            // isolation test checks that the database hides them.
            new TenantResource(
                'collaborator_services',
                function (string $barbershopId): string {
                    $collaboratorId = self::createCollaborator($barbershopId);
                    $serviceId = (string) Str::uuid7();
                    DB::table('services')->insert(['id' => $serviceId, 'barbershop_id' => $barbershopId, 'name' => 'Corte infantil', 'duration_minutes' => 30, 'price' => 5000]);
                    $id = (string) Str::uuid7();
                    DB::table('collaborator_services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'collaborator_id' => $collaboratorId, 'service_id' => $serviceId]);

                    return $id;
                },
                'api/v1/barberias/{barberia}/administracion/colaboradores',
                [],
            ),
            ...array_map(
                fn (string $table): TenantResource => new TenantResource(
                    $table,
                    function (string $barbershopId) use ($table): string {
                        $id = (string) Str::uuid7();
                        DB::table($table)->insert([
                            'id' => $id,
                            'barbershop_id' => $barbershopId,
                            'collaborator_id' => self::createCollaborator($barbershopId),
                            'day_of_week' => 1,
                            'starts_at' => '09:00',
                            'ends_at' => '12:00',
                        ]);

                        return $id;
                    },
                    'api/v1/barberias/{barberia}/administracion/colaboradores',
                    [],
                ),
                ['collaborator_shifts', 'collaborator_breaks'],
            ),
        ];
    }

    /** Insert an account and its collaborator profile in a barbershop and return the identifier of the profile. */
    private static function createCollaborator(string $barbershopId): string
    {
        $userId = (string) Str::uuid7();
        $collaboratorId = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => 'Kevin Solano', 'email' => "{$userId}@barberhub.test", 'password' => 'hash']);
        DB::table('collaborators')->insert(['id' => $collaboratorId, 'barbershop_id' => $barbershopId, 'user_id' => $userId]);

        return $collaboratorId;
    }
}
