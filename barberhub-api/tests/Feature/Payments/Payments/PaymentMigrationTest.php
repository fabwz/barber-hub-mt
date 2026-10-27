<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The migration of the payments rolls back without leftovers, so migrate:fresh keeps working. */
final class PaymentMigrationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string MIGRATION = '2026_10_26_110000_create_payments_table';

    /** Rolling back drops the table with its constraints, index and policy; applying again brings them back. */
    public function test_migration_rolls_back_and_applies_again_without_leftovers(): void
    {
        $admin = DB::connection('pgsql_admin');
        $schema = Schema::connection('pgsql_admin');
        $migration = require base_path('database/migrations/'.self::MIGRATION.'.php');
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');

        try {
            $migration->down();

            $this->assertFalse($schema->hasTable('payments'));
            $this->assertSame(0, $admin->table('pg_policies')->where('tablename', 'payments')->count());
            $this->assertSame(0, $admin->table('pg_constraint')->where('conname', 'like', 'payments%')->count());
            $this->assertSame(0, $admin->table('pg_indexes')->where('indexname', 'like', 'payments%')->count());
            $this->assertTrue($schema->hasTable('reservations'));

            $migration->up();

            $this->assertTrue($schema->hasTable('payments'));
            $this->assertSame(1, $admin->table('pg_policies')->where('tablename', 'payments')->count());
            $this->assertSame(1, $admin->table('pg_indexes')->where('indexname', 'payments_one_active_per_reservation_idx')->count());
            $this->assertSame(1, $admin->table('pg_constraint')->where('conname', 'payments_reservation_id_barbershop_id_foreign')->where('contype', 'f')->count());
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
