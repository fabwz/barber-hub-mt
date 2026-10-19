<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class MigrationReversibilityTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** Every migration rolls back and applies again without leftovers. */
    public function test_migrations_roll_back_and_apply_again(): void
    {
        $schema = Schema::connection('pgsql_admin');
        $migrated = $schema->getTableListing(schemaQualified: false);
        $defaultPrivileges = $this->defaultPrivilegeCount();

        // Guards the leftover check below: the migrations do create default privileges.
        $this->assertGreaterThan(0, $defaultPrivileges);

        $this->assertSame(0, Artisan::call('migrate:reset', ['--database' => 'pgsql_admin']));

        $this->assertSame(['migrations'], $schema->getTableListing(schemaQualified: false));
        $this->assertSame(0, $this->defaultPrivilegeCount());

        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_admin']));

        $this->assertSame($migrated, $schema->getTableListing(schemaQualified: false));
        $this->assertSame($defaultPrivileges, $this->defaultPrivilegeCount());
    }

    /** Default privileges of the public schema, which migrations alter without creating tables. */
    private function defaultPrivilegeCount(): int
    {
        return (int) DB::connection('pgsql_admin')->scalar(
            "select count(*) from pg_default_acl d join pg_namespace n on n.oid = d.defaclnamespace where n.nspname = 'public'",
        );
    }
}
