<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\BarbershopIsolation;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/**
 * The second barrier (MT-06): what PostgreSQL shows and accepts on a table of a barbershop, tried on a probe table
 * because the project has no operational table yet. Each later table gets its policy from its own migration.
 */
final class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabaseAsOwner, ScopesQueriesToBarbershop;

    private const string BARBERSHOP_A = '0192f0c4-0000-7000-8000-0000000000aa';

    private const string BARBERSHOP_B = '0192f0c4-0000-7000-8000-0000000000bb';

    /** Create the probe table and give each of two barbershops its rows, through the application role. */
    protected function setUp(): void
    {
        parent::setUp();

        BarbershopIsolation::createProbe(DB::connection('pgsql_admin'));
        // Registered after the rollback of the refresh trait, so the application's transaction no longer holds the table.
        $this->beforeApplicationDestroyed(fn () => BarbershopIsolation::dropProbe(DB::connection('pgsql_admin')));

        $this->assertSame(config('database.connections.pgsql.username'), DB::scalar('select current_user'));

        $this->scopeQueriesTo(self::BARBERSHOP_A);
        DB::table(BarbershopIsolation::PROBE)->insert([
            ['barbershop_id' => self::BARBERSHOP_A, 'label' => 'a1'],
            ['barbershop_id' => self::BARBERSHOP_A, 'label' => 'a2'],
        ]);
        $this->scopeQueriesTo(self::BARBERSHOP_B);
        DB::table(BarbershopIsolation::PROBE)->insert(['barbershop_id' => self::BARBERSHOP_B, 'label' => 'b1']);
    }

    /** With the context of a barbershop only its rows are visible. */
    public function test_only_the_rows_of_the_barbershop_in_context_are_visible(): void
    {
        $this->scopeQueriesTo(self::BARBERSHOP_A);
        $this->assertSame(['a1', 'a2'], DB::table(BarbershopIsolation::PROBE)->orderBy('label')->pluck('label')->all());

        $this->scopeQueriesTo(self::BARBERSHOP_B);
        $this->assertSame(['b1'], DB::table(BarbershopIsolation::PROBE)->pluck('label')->all());
    }

    /** Without a context no row is visible and no update or delete reaches one. */
    public function test_without_a_context_no_row_is_visible_or_changed(): void
    {
        $this->scopeQueriesTo(null);

        $this->assertSame(0, DB::table(BarbershopIsolation::PROBE)->count());
        $this->assertSame(0, DB::table(BarbershopIsolation::PROBE)->update(['label' => 'x']));
        $this->assertSame(0, DB::table(BarbershopIsolation::PROBE)->delete());

        $this->scopeQueriesTo(self::BARBERSHOP_A);
        $this->assertSame(2, DB::table(BarbershopIsolation::PROBE)->count());
    }

    /** A query that forgets the barbershop filter, in any form, returns no row of another barbershop. */
    public function test_a_query_that_forgets_the_filter_returns_no_row_of_another_barbershop(): void
    {
        $this->scopeQueriesTo(self::BARBERSHOP_B);
        $otherId = (string) DB::table(BarbershopIsolation::PROBE)->value('id');

        $this->scopeQueriesTo(self::BARBERSHOP_A);

        $this->assertNotContains('b1', DB::table(BarbershopIsolation::PROBE)->pluck('label')->all());
        $this->assertNotContains('b1', array_column(DB::select('select * from tenancy_probe'), 'label'));
        $this->assertNull(DB::table(BarbershopIsolation::PROBE)->where('id', $otherId)->first());
        $this->assertSame(0, DB::table(BarbershopIsolation::PROBE)->where('label', 'b1')->count());
    }

    /** Changing or deleting a row of another barbershop by its identifier reaches nothing, and the row stays. */
    public function test_cannot_update_or_delete_a_row_of_another_barbershop(): void
    {
        $this->scopeQueriesTo(self::BARBERSHOP_B);
        $otherId = (string) DB::table(BarbershopIsolation::PROBE)->value('id');

        $this->scopeQueriesTo(self::BARBERSHOP_A);
        $this->assertSame(0, DB::table(BarbershopIsolation::PROBE)->where('id', $otherId)->update(['label' => 'hijacked']));
        $this->assertSame(0, DB::table(BarbershopIsolation::PROBE)->where('id', $otherId)->delete());

        $this->scopeQueriesTo(self::BARBERSHOP_B);
        $this->assertSame(['b1'], DB::table(BarbershopIsolation::PROBE)->pluck('label')->all());
    }

    /** Inserting a row for another barbershop fails with the row-level security error. */
    public function test_inserting_a_row_for_another_barbershop_fails(): void
    {
        $this->scopeQueriesTo(self::BARBERSHOP_A);

        $this->assertRowLevelSecurityViolation(
            fn () => DB::table(BarbershopIsolation::PROBE)->insert(['barbershop_id' => self::BARBERSHOP_B, 'label' => 'intruder']),
        );
    }

    /** Inserting without a context fails the same way. */
    public function test_inserting_without_a_context_fails(): void
    {
        $this->scopeQueriesTo(null);

        $this->assertRowLevelSecurityViolation(
            fn () => DB::table(BarbershopIsolation::PROBE)->insert(['barbershop_id' => self::BARBERSHOP_A, 'label' => 'orphan']),
        );
    }

    /** Moving a row to another barbershop fails with the same error and the row stays where it was. */
    public function test_moving_a_row_to_another_barbershop_fails(): void
    {
        $this->scopeQueriesTo(self::BARBERSHOP_A);

        $this->assertRowLevelSecurityViolation(
            fn () => DB::table(BarbershopIsolation::PROBE)->update(['barbershop_id' => self::BARBERSHOP_B]),
        );
        $this->assertSame(2, DB::table(BarbershopIsolation::PROBE)->count());
    }

    /** The transaction of the middleware scopes the rows the same way. */
    public function test_the_barbershop_transaction_limits_the_rows(): void
    {
        $transaction = new BarbershopTransaction;

        $this->assertSame(2, $transaction->run(self::BARBERSHOP_A, fn (): int => DB::table(BarbershopIsolation::PROBE)->count()));
        $this->assertSame(1, $transaction->run(self::BARBERSHOP_B, fn (): int => DB::table(BarbershopIsolation::PROBE)->count()));
        $this->assertSame(0, $transaction->run(null, fn (): int => DB::table(BarbershopIsolation::PROBE)->count()));
    }

    /**
     * The table owner is limited too, because the policy is forced: the migrations and the seeders connect as the owner
     * and must set a barbershop like everyone else. Its own transaction on the administrative connection.
     */
    public function test_the_table_owner_is_limited_as_well(): void
    {
        $admin = DB::connection('pgsql_admin');
        $this->assertSame(config('database.connections.pgsql_admin.username'), $admin->scalar('select current_user'));

        $admin->beginTransaction();

        try {
            $admin->statement('select set_config(?, ?, true)', ['app.barbershop_id', '']);
            $this->assertSame(0, $admin->table(BarbershopIsolation::PROBE)->count());
            $this->assertRowLevelSecurityViolation(
                fn () => $admin->table(BarbershopIsolation::PROBE)->insert(['barbershop_id' => self::BARBERSHOP_A, 'label' => 'o0']),
                $admin,
            );
        } finally {
            $admin->rollBack();
        }

        $admin->beginTransaction();

        try {
            $admin->statement('select set_config(?, ?, true)', ['app.barbershop_id', self::BARBERSHOP_A]);
            $admin->table(BarbershopIsolation::PROBE)->insert(['barbershop_id' => self::BARBERSHOP_A, 'label' => 'owner-a']);
            $this->assertSame(['owner-a'], $admin->table(BarbershopIsolation::PROBE)->pluck('label')->all());
            $this->assertRowLevelSecurityViolation(
                fn () => $admin->table(BarbershopIsolation::PROBE)->insert(['barbershop_id' => self::BARBERSHOP_B, 'label' => 'owner-b']),
                $admin,
            );
        } finally {
            $admin->rollBack();
        }
    }

    /**
     * Run a write inside a savepoint and expect PostgreSQL to reject it by its row-level security policy
     * (SQLSTATE 42501; the message depends on the language of the server).
     *
     * @param  Closure(): mixed  $write
     */
    private function assertRowLevelSecurityViolation(Closure $write, ?ConnectionInterface $connection = null): void
    {
        try {
            ($connection ?? DB::connection())->transaction($write);
            $this->fail('A row crossed to another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
            $this->assertStringContainsString(BarbershopIsolation::PROBE, $exception->getMessage());
        }
    }
}
