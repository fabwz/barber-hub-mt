<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

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
    public const array PUBLIC_ROUTES = [];

    /**
     * The resources to cover. Empty until the first table of a barbershop exists.
     *
     * @return list<TenantResource>
     */
    public static function all(): array
    {
        return [];
    }
}
