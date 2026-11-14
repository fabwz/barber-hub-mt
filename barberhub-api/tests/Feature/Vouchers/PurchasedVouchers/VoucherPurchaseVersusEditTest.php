<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\PurchasedVouchers;

use Closure;
use Illuminate\Support\Facades\DB;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\Support\Vouchers\VoucherFactory;
use Tests\TestCase;

/**
 * The purchase of a voucher (#88) against the owner editing its sessions (#87), the race that would leave a voucher with terms that
 * were never on sale (RF-26, RF-27). Both take the lock of the row of the voucher: the first two tests hold that lock here and prove
 * that each request waits for it, from its own process and connection; the last one lets both run at once and checks what is left.
 */
final class VoucherPurchaseVersusEditTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private string $account;

    private string $definition;

    /** Start from an empty database with Pura Navaja selling "Bono 5 cortes" and an account without a role, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            $this->account = $this->insertUser('Ana Rojas');
            $this->definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
            DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'full', 'payment_gateway' => 'onvo']);
        });
    }

    /** Remove what the processes committed, even when the test failed. */
    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    /** The test commits its rows, so it does not open the transaction that rolls them back. */
    public function beginDatabaseTransaction(): void {}

    /** The purchase holds the voucher on sale: an edit of it, from another connection, has to wait for the purchase to end (RF-26). */
    public function test_an_edit_waits_for_the_purchase_that_holds_the_voucher(): void
    {
        [$edit] = $this->whileHoldingTheVoucher(['edit']);

        $this->assertSame('blocked', $edit['outcome']);
        // It waited before reading the sales: had it read them first, a purchase in flight could slip in between the check and the write.
        $this->assertNotContains('purchased_vouchers', $edit['tables']);
        $this->assertSame(5, $this->sessionsOfTheDefinition());
    }

    /** The edit holds the voucher too: a purchase has to wait for it, so it never copies terms that are being changed (RF-27). */
    public function test_a_purchase_waits_for_the_edit_that_holds_the_voucher(): void
    {
        [$buy] = $this->whileHoldingTheVoucher(['buy']);

        $this->assertSame('blocked', $buy['outcome']);
        // It waited before creating the role or the voucher: the lock is the first thing the purchase takes.
        $this->assertSame([], array_values(array_intersect($buy['tables'], ['memberships', 'purchased_vouchers', 'payments'])));
        $this->assertSame(0, $this->vouchersBought());
    }

    /** Both at once: whichever wins, the voucher bought has the sessions the definition ends with, and a sale that came first blocks the edit. */
    public function test_a_purchase_and_an_edit_at_once_never_leave_terms_that_were_not_on_sale(): void
    {
        $outcomes = Race::run(base_path('tests/Support/Concurrency/buy_or_edit_voucher_worker.php'), [
            [$this->puraNavaja, $this->definition, 'buy', $this->account, '0', VoucherFactory::now()->format(DATE_ATOM)],
            [$this->puraNavaja, $this->definition, 'edit', $this->account, '0', VoucherFactory::now()->format(DATE_ATOM)],
        ]);
        $outcomes = array_map(fn (string $output): string => $this->resultOf($output)['outcome'], $outcomes);

        $this->assertSame('bought', $outcomes[0], json_encode($outcomes) ?: '');
        $this->assertContains($outcomes[1], ['edited', 'bono-con-ventas'], json_encode($outcomes) ?: '');
        $this->assertSame(1, $this->vouchersBought());
        $this->assertSame($this->sessionsOfTheDefinition(), $this->sessionsOfTheVoucher());
        $this->assertSame($outcomes[1] === 'edited' ? 8 : 5, $this->sessionsOfTheVoucher());
    }

    /**
     * Hold the row of the voucher in this connection, as the request that has it does, and send the others against it with a short
     * lock timeout, so a request that has to wait answers "blocked".
     *
     * @param  list<string>  $modes
     * @return list<array{outcome: string, tables: list<string>}>
     */
    private function whileHoldingTheVoucher(array $modes): array
    {
        $outcomes = [];

        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function () use ($modes, &$outcomes): void {
            DB::table('voucher_definitions')->where('id', $this->definition)->lockForUpdate()->first();

            $raw = Race::run(base_path('tests/Support/Concurrency/buy_or_edit_voucher_worker.php'), array_map(
                fn (string $mode): array => [$this->puraNavaja, $this->definition, $mode, $this->account, '1500', VoucherFactory::now()->format(DATE_ATOM)],
                $modes,
            ));
            $outcomes = array_map(fn (string $output): array => $this->resultOf($output), $raw);
        });

        return $outcomes;
    }

    /**
     * What a process printed: how it ended and the tables its queries touched.
     *
     * @return array{outcome: string, tables: list<string>}
     */
    private function resultOf(string $output): array
    {
        /** @var array{outcome: string, tables: list<string>} $decoded */
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function sessionsOfTheDefinition(): int
    {
        return $this->inPuraNavaja(fn (): int => (int) DB::table('voucher_definitions')->where('id', $this->definition)->value('sessions_count'));
    }

    private function sessionsOfTheVoucher(): int
    {
        return $this->inPuraNavaja(fn (): int => (int) DB::table('purchased_vouchers')->value('sessions_count'));
    }

    private function vouchersBought(): int
    {
        return $this->inPuraNavaja(fn (): int => DB::table('purchased_vouchers')->count());
    }

    /**
     * Read as Pura Navaja sees its rows.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function inPuraNavaja(Closure $read): mixed
    {
        return $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, $read);
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
