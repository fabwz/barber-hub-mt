<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The migrations of the vouchers roll back without leftovers, so migrate:fresh keeps working, and they bring back everything when applied again. */
final class VoucherMigrationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string DEFINITIONS = '2026_11_13_100000_create_voucher_definitions_tables';

    private const string PURCHASED = '2026_11_13_110000_create_purchased_vouchers_tables';

    private const array TABLES = ['voucher_definitions', 'voucher_definition_services', 'purchased_vouchers', 'voucher_movements'];

    /** Rolling back drops the four tables with their constraints, indexes, policies, trigger and function; applying again brings them back. */
    public function test_migrations_roll_back_and_apply_again_without_leftovers(): void
    {
        $admin = DB::connection('pgsql_admin');
        $schema = Schema::connection('pgsql_admin');
        $definitions = require base_path('database/migrations/'.self::DEFINITIONS.'.php');
        $purchased = require base_path('database/migrations/'.self::PURCHASED.'.php');
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');

        try {
            $purchased->down();

            $this->assertFalse($schema->hasTable('purchased_vouchers'));
            $this->assertFalse($schema->hasTable('voucher_movements'));
            $this->assertTrue($schema->hasTable('voucher_definitions'));
            $this->assertSame(0, $admin->table('pg_proc')->where('proname', 'fn_voucher_movements_append_only')->count());
            $this->assertSame(0, $admin->table('pg_policies')->whereIn('tablename', ['purchased_vouchers', 'voucher_movements'])->count());

            $definitions->down();

            foreach (self::TABLES as $table) {
                $this->assertFalse($schema->hasTable($table), $table);
            }
            $this->assertSame(0, $admin->table('pg_policies')->where('tablename', 'like', 'voucher%')->count());
            $this->assertSame(0, $admin->table('pg_constraint')->where('conname', 'like', 'voucher%')->count());
            $this->assertSame(0, $admin->table('pg_constraint')->where('conname', 'like', 'purchased_voucher%')->count());
            $this->assertSame(0, $admin->table('pg_indexes')->where('indexname', 'like', 'voucher%')->count());
            $this->assertSame(0, $admin->table('pg_indexes')->where('indexname', 'like', 'purchased_voucher%')->count());
            $this->assertTrue($schema->hasTable('reservations'));
            $this->assertTrue($schema->hasTable('memberships'));

            $definitions->up();
            $purchased->up();

            foreach (self::TABLES as $table) {
                $this->assertTrue($schema->hasTable($table), $table);
            }
            $this->assertSame(4, $admin->table('pg_policies')->where('tablename', 'like', '%voucher%')->count());
            $this->assertSame(1, $admin->table('pg_trigger')->where('tgname', 'trg_voucher_movements_append_only')->count());
            $this->assertSame(1, $admin->table('pg_proc')->where('proname', 'fn_voucher_movements_append_only')->count());
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
