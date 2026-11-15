<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/**
 * The technical record only grows (SE-04): the application role has no privilege to change or delete an entry, and a trigger
 * refuses it to every other role, the owner of the table included. The rows are committed for real, because the owner
 * connection is another session and cannot see what the transaction of a test has not committed.
 */
final class AuditLogIsAppendOnlyTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string INSUFFICIENT_PRIVILEGE = '42501';

    private const string RESTRICT_VIOLATION = '23001';

    private string $barbershop;

    private string $entry;

    /** A barbershop with an entry in its record, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        $this->barbershop = (string) Str::uuid7();
        $this->entry = (string) Str::uuid7();
        $actor = (string) Str::uuid7();

        DB::transaction(function () use ($actor): void {
            DB::table('barbershops')->insert(['id' => $this->barbershop, 'name' => 'Pura Navaja', 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => 'pura-navaja']);
            DB::table('users')->insert(['id' => $actor, 'name' => 'Ana Rojas', 'email' => 'ana@barberhub.test', 'password' => 'hash']);
            $this->app->make(BarbershopTransaction::class)->run($this->barbershop, fn () => DB::table('audit_log')->insert([
                'id' => $this->entry,
                'barbershop_id' => $this->barbershop,
                'actor_user_id' => $actor,
                'action' => 'barbershop.features.updated',
                'subject_type' => 'barbershop',
                'subject_id' => $this->barbershop,
                'changes' => '{"vouchers_enabled": {"previous": false, "new": true}}',
            ]));
        });
    }

    /** Remove what the test committed, even when it failed. */
    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    /** The test commits its rows, so it does not open the transaction that rolls them back. */
    public function beginDatabaseTransaction(): void {}

    /** The application role can append and read, but not change or delete an entry. */
    public function test_the_application_role_cannot_change_or_delete_an_entry(): void
    {
        $this->assertRejected(self::INSUFFICIENT_PRIVILEGE, fn () => DB::table('audit_log')->where('id', $this->entry)->update(['action' => 'x']));
        $this->assertRejected(self::INSUFFICIENT_PRIVILEGE, fn () => DB::table('audit_log')->where('id', $this->entry)->delete());
        $this->assertSame(1, $this->app->make(BarbershopTransaction::class)->run($this->barbershop, fn (): int => DB::table('audit_log')->count()));
    }

    /** The owner of the table is bound by the trigger: its change or delete is refused even with the barbershop in the context. */
    public function test_the_owner_of_the_table_cannot_change_or_delete_an_entry_either(): void
    {
        $admin = DB::connection('pgsql_admin');

        foreach (["update audit_log set action = 'x' where id = ?", 'delete from audit_log where id = ?'] as $statement) {
            try {
                $admin->transaction(function () use ($admin, $statement): void {
                    $admin->statement("select set_config('app.barbershop_id', ?, true)", [$this->barbershop]);
                    $admin->statement($statement, [$this->entry]);
                });
                $this->fail('The owner changed the record.');
            } catch (QueryException $exception) {
                $this->assertSame(self::RESTRICT_VIOLATION, (string) $exception->getCode(), $exception->getMessage());
            }
        }

        $this->assertSame(1, $admin->transaction(function () use ($admin): int {
            $admin->statement("select set_config('app.barbershop_id', ?, true)", [$this->barbershop]);

            return (int) $admin->scalar('select count(*) from audit_log');
        }));
    }

    /** What the entry keeps is a JSON object, never a list or a loose value. */
    public function test_the_changes_must_be_an_object(): void
    {
        $this->assertRejected('23514', fn () => DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'barbershop_id' => $this->barbershop,
            'actor_user_id' => DB::table('users')->value('id'),
            'action' => 'barbershop.features.updated',
            'subject_type' => 'barbershop',
            'subject_id' => $this->barbershop,
            'changes' => '["no es un objeto"]',
        ]));
    }

    /**
     * Run a write as the barbershop that must be rejected with the SQLSTATE.
     *
     * @param  Closure(): mixed  $write
     */
    private function assertRejected(string $sqlState, Closure $write): void
    {
        try {
            $this->app->make(BarbershopTransaction::class)->run($this->barbershop, $write);
            $this->fail("The write was accepted; SQLSTATE {$sqlState} was expected.");
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, (string) $exception->getCode(), $exception->getMessage());
        }
    }

    /** Empty the tables the test fills. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
