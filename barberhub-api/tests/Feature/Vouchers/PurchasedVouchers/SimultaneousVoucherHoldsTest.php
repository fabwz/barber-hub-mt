<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\PurchasedVouchers;

use DateTimeImmutable;
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
 * Requests for the last sessions of a voucher at the same instant (BO-03, RNF-05). Each one is a PHP process with its own
 * connection that runs the atomic operation of the repository, and the rows are committed for real, so the conditional update
 * of the database is what decides: the sessions go to as many requests as there are, the rest are refused with
 * "saldo-insuficiente", and the balance ends at zero, never at -1. The broad test of the use case is #133.
 */
final class SimultaneousVoucherHoldsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private string $membership;

    /** @var list<string> */
    private array $reservations = [];

    /** Start from an empty database with Pura Navaja and a customer, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            [, $this->membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
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

    /** One session and two requests at once: one is confirmed, the other gets "saldo-insuficiente", and the balance ends at 0 (RF-28). */
    public function test_two_requests_for_the_last_session_confirm_one(): void
    {
        $voucher = $this->voucherWith(sessions: 1, reservations: 2);

        $outcomes = $this->race($voucher);

        $this->assertSame(['confirmed', 'saldo-insuficiente'], $this->sorted($outcomes));
        $this->assertEnds($voucher, balance: 0, holds: 1);
    }

    /** Ten requests for a voucher of three sessions: exactly three are confirmed, the other seven are refused, the balance is 0 (RNF-05). */
    public function test_n_requests_for_k_sessions_confirm_exactly_k(): void
    {
        $voucher = $this->voucherWith(sessions: 3, reservations: 10);

        $outcomes = $this->race($voucher);

        $this->assertSame(3, count(array_filter($outcomes, fn (string $outcome): bool => $outcome === 'confirmed')), json_encode($outcomes) ?: '');
        $this->assertSame(7, count(array_filter($outcomes, fn (string $outcome): bool => $outcome === 'saldo-insuficiente')), json_encode($outcomes) ?: '');
        $this->assertEnds($voucher, balance: 0, holds: 3);
    }

    /** More sessions than requests: all are confirmed and the rest of the balance stays. */
    public function test_requests_fewer_than_the_sessions_all_confirm(): void
    {
        $voucher = $this->voucherWith(sessions: 5, reservations: 3);

        $outcomes = $this->race($voucher);

        $this->assertSame(['confirmed', 'confirmed', 'confirmed'], $outcomes);
        $this->assertEnds($voucher, balance: 2, holds: 3);
    }

    /** An active voucher of Pura Navaja with the sessions, and the reservations that will ask for them, committed. */
    private function voucherWith(int $sessions, int $reservations): string
    {
        $voucher = '';
        DB::transaction(function () use ($sessions, $reservations, &$voucher): void {
            $definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado], sessions: $sessions);
            $voucher = $this->insertPurchasedVoucher($this->puraNavaja, $this->membership, $definition, sessions: $sessions);

            for ($n = 0; $n < $reservations; $n++) {
                $start = (new DateTimeImmutable('2030-03-05 07:00:00-06:00'))->modify("+{$n} hours");
                $this->reservations[] = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->membership, $start->format('Y-m-d H:i:sP'));
            }
        });

        return $voucher;
    }

    /**
     * One process per reservation, all asking for a session of the voucher.
     *
     * @return list<string>
     */
    private function race(string $voucher): array
    {
        $now = VoucherFactory::now()->format(DATE_ATOM);
        $outputs = Race::run(base_path('tests/Support/Concurrency/hold_voucher_session_worker.php'), array_map(
            fn (string $reservation): array => [$this->puraNavaja, $voucher, $reservation, $now],
            $this->reservations,
        ));

        return array_map(function (string $output): string {
            /** @var array{outcome: string} $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            return $decoded['outcome'];
        }, $outputs);
    }

    /**
     * @param  list<string>  $outcomes
     * @return list<string>
     */
    private function sorted(array $outcomes): array
    {
        sort($outcomes);

        return $outcomes;
    }

    /** The balance is what is left, the record has one hold per confirmed request and adds up to the balance. */
    private function assertEnds(string $voucher, int $balance, int $holds): void
    {
        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function () use ($voucher, $balance, $holds): void {
            $this->assertSame($balance, (int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions'));
            $this->assertSame($holds, DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->where('type', 'hold')->count());
            $this->assertSame($balance, (int) DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->sum('sessions_delta'));
        });
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
