<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class PublicLinkMigrationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string MIGRATION = 'database/migrations/2026_10_19_140000_add_public_link_to_barbershops_table.php';

    /**
     * The barbershops that already exist when the migration runs get the link their name gives, the oldest first,
     * and the column ends up required and unique (RF-02, MT-07).
     */
    public function test_existing_barbershops_receive_their_links(): void
    {
        $admin = DB::connection('pgsql_admin');
        $schema = Schema::connection('pgsql_admin');

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--database' => 'pgsql_admin']));

        try {
            $this->assertFalse($schema->hasColumn('barbershops', 'public_link'));

            foreach ([
                '2026-01-01 10:00:00' => 'Barbería Pura Navaja',
                '2026-01-02 10:00:00' => 'Pura Navaja',
                '2026-01-03 10:00:00' => 'Barbería Ñandú',
                '2026-01-04 10:00:00' => 'BARBERÍA PURA NAVAJA',
            ] as $createdAt => $name) {
                $admin->table('barbershops')->insert(['name' => $name, 'city' => 'San Carlos', 'phone' => '2460-0000', 'created_at' => $createdAt]);
            }

            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_admin']));

            $this->assertSame(
                ['pura-navaja', 'pura-navaja-2', 'nandu', 'pura-navaja-3'],
                $admin->table('barbershops')->orderBy('created_at')->pluck('public_link')->all(),
            );
            $this->assertSame('NO', $admin->scalar("select is_nullable from information_schema.columns where table_name = 'barbershops' and column_name = 'public_link'"));
            $this->assertTrue(collect($schema->getIndexes('barbershops'))->contains(fn (array $index): bool => $index['name'] === 'barbershops_public_link_unique' && $index['unique']));
        } finally {
            // Whatever happened above, leave the schema migrated and the table empty for the other tests.
            Artisan::call('migrate', ['--database' => 'pgsql_admin']);
            $admin->table('barbershops')->delete();
        }
    }

    /** Rolling the migration back drops the link with its key and check, and applying it again restores them. */
    public function test_migration_is_reversible(): void
    {
        $schema = Schema::connection('pgsql_admin');

        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--database' => 'pgsql_admin']));

            $this->assertSame(['id', 'name', 'city', 'phone', 'created_at', 'updated_at', 'payment_gateway'], $schema->getColumnListing('barbershops'));
        } finally {
            Artisan::call('migrate', ['--database' => 'pgsql_admin']);
        }

        $this->assertContains('public_link', $schema->getColumnListing('barbershops'));
    }
}
