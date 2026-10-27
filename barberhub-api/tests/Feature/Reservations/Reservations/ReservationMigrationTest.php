<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The migration of the reservations rolls back without leftovers, so migrate:fresh keeps working (ADR 0009). */
final class ReservationMigrationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string MIGRATION = '2026_10_20_150000_create_reservations_tables';

    // The expiry column belongs to the reservations table, so it goes before the table when rolling back and after it when applying again.
    private const string EXPIRES_AT = '2026_10_26_120000_add_expires_at_to_reservations_table';

    // The payments point at the reservations, so they go before them when rolling back and after them when applying again.
    private const string PAYMENTS = '2026_10_26_110000_create_payments_table';

    // The mark of the payments to give back is a column of that table: it goes before it when rolling back and after it when applying again.
    private const string REFUND_PENDING = '2026_10_27_100000_add_refund_pending_to_payments_table';

    /** Rolling back drops both tables with their constraints and policies and keeps the extension; applying again brings them back. */
    public function test_migration_rolls_back_and_applies_again_without_leftovers(): void
    {
        $admin = DB::connection('pgsql_admin');
        $schema = Schema::connection('pgsql_admin');
        $migration = require base_path('database/migrations/'.self::MIGRATION.'.php');
        $payments = require base_path('database/migrations/'.self::PAYMENTS.'.php');
        $expiresAt = require base_path('database/migrations/'.self::EXPIRES_AT.'.php');
        $refundPending = require base_path('database/migrations/'.self::REFUND_PENDING.'.php');
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');

        try {
            $expiresAt->down();
            $refundPending->down();
            $payments->down();
            $migration->down();

            $this->assertFalse($schema->hasTable('reservations'));
            $this->assertFalse($schema->hasTable('reservation_status_history'));
            $this->assertSame(0, $admin->table('pg_policies')->whereIn('tablename', ['reservations', 'reservation_status_history'])->count());
            $this->assertSame(0, $admin->table('pg_constraint')->where('conname', 'like', 'reservation%')->count());
            $this->assertSame(1, $admin->table('pg_extension')->where('extname', 'btree_gist')->count());

            $migration->up();
            $payments->up();
            $refundPending->up();
            $expiresAt->up();

            $this->assertTrue($schema->hasTable('reservations'));
            $this->assertTrue($schema->hasTable('reservation_status_history'));
            $this->assertSame(2, $admin->table('pg_policies')->whereIn('tablename', ['reservations', 'reservation_status_history'])->count());
            $this->assertSame(2, $admin->table('pg_constraint')->whereIn('conname', ['reservations_no_overlap_excl', 'reservations_customer_no_overlap_excl'])->where('contype', 'x')->count());
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
