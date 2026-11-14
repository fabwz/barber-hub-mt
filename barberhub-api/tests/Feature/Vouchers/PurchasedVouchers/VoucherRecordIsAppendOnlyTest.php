<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\PurchasedVouchers;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\TestCase;

/**
 * The record of the balances only grows (BO-09): the application role has no privilege to change or delete an entry, and a
 * trigger refuses it to every other role, the owner of the table included. The rows are committed for real, because the owner
 * connection is another session and cannot see what the transaction of a test has not committed.
 */
final class VoucherRecordIsAppendOnlyTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private const string INSUFFICIENT_PRIVILEGE = '42501';

    private const string RESTRICT_VIOLATION = '23001';

    private string $movement;

    /** Pura Navaja with a voucher, and an entry in its record, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            [, $membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
            $reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $membership, '2030-03-05 10:00:00-06:00');
            $definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
            $voucher = $this->insertPurchasedVoucher($this->puraNavaja, $membership, $definition);
            $this->movement = $this->insertVoucherMovement($this->puraNavaja, $voucher, 'hold', -1, $reservation);
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

    /** The application role can read and append, but not change or delete an entry. */
    public function test_the_application_role_cannot_change_or_delete_an_entry(): void
    {
        $this->assertRejected(self::INSUFFICIENT_PRIVILEGE, fn () => DB::table('voucher_movements')->where('id', $this->movement)->update(['sessions_delta' => 0]));
        $this->assertRejected(self::INSUFFICIENT_PRIVILEGE, fn () => DB::table('voucher_movements')->where('id', $this->movement)->delete());
        $this->assertSame(2, $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, fn (): int => DB::table('voucher_movements')->count()));
    }

    /** The owner of the table is bound by the trigger: its change or delete is refused even with the barbershop in the context. */
    public function test_the_owner_of_the_table_cannot_change_or_delete_an_entry_either(): void
    {
        $admin = DB::connection('pgsql_admin');

        foreach (['update voucher_movements set sessions_delta = 0 where id = ?', 'delete from voucher_movements where id = ?'] as $statement) {
            try {
                $admin->transaction(function () use ($admin, $statement): void {
                    $admin->statement("select set_config('app.barbershop_id', ?, true)", [$this->puraNavaja]);
                    $admin->statement($statement, [$this->movement]);
                });
                $this->fail('The owner changed the record.');
            } catch (QueryException $exception) {
                $this->assertSame(self::RESTRICT_VIOLATION, (string) $exception->getCode(), $exception->getMessage());
            }
        }

        $this->assertSame(2, $admin->transaction(function () use ($admin): int {
            $admin->statement("select set_config('app.barbershop_id', ?, true)", [$this->puraNavaja]);

            return (int) $admin->scalar('select count(*) from voucher_movements');
        }));
    }

    /**
     * Run a write as Pura Navaja that must be rejected with the SQLSTATE.
     *
     * @param  Closure(): mixed  $write
     */
    private function assertRejected(string $sqlState, Closure $write): void
    {
        try {
            $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, $write);
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
