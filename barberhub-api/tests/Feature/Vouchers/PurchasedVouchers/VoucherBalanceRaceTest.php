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
use Tests\TestCase;

/**
 * Reservations paid with a voucher at the same instant, through the real use case of the booking (RF-28, BO-03, BO-04, BO-08,
 * RNF-05). Each request is a PHP process with its own connection and the rows are committed for real, with nothing wrapped in a
 * test transaction, so they are removed by hand at the start and at the end. The requests are Ana's, each for a different space
 * of a different collaborator: that way nothing but the balance of the voucher makes them wait for each other (the lock of the
 * collaborator would otherwise take them one by one, DI-01, and the voucher would never race).
 *
 * The conditional update of the repository is the control. The last test removes it, by taking the session with a read and a
 * write, and shows that the same race then sells more sessions than the voucher has, which proves the tests above notice its absence.
 */
final class VoucherBalanceRaceTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private const string NOW = '2030-03-01T08:00:00-06:00';

    private string $user;

    private string $membership;

    private string $definition;

    /** @var list<string> */
    private array $collaborators = [];

    /** Start from an empty database with Pura Navaja charging a deposit, Ana as its customer and ten collaborators, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            $this->scopeQueriesTo($this->puraNavaja);
            DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'onvo', 'payment_mode' => 'deposit', 'deposit_percentage' => 30]);
            [$this->user, $this->membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
            $this->definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);

            foreach (range(1, 10) as $number) {
                $this->collaborators[] = $this->insertCollaborator($this->puraNavaja, "Barbero {$number}", [$this->degradado], [[2, '09:00', '19:00']]);
            }
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

    /** One session and two reservations at once: one is confirmed, the other gets `saldo-insuficiente` and leaves no reservation, and the balance ends at 0 (BO-03). */
    public function test_two_reservations_for_the_last_session_confirm_one(): void
    {
        $voucher = $this->voucher(sessions: 1);

        $outcomes = $this->race(2, 'control');

        $this->assertSame(['confirmed', 'saldo-insuficiente'], $this->sorted($outcomes));
        $this->assertLedger($voucher, balance: 0, holds: 1);
        $this->assertReservations(confirmed: 1, byVoucher: [$voucher => 1]);
    }

    /** Ten reservations for a voucher of three sessions, each in a different space: exactly three are confirmed, seven get `saldo-insuficiente` and the balance ends at 0 (BO-03, RNF-05). */
    public function test_ten_reservations_for_three_sessions_confirm_exactly_three(): void
    {
        $voucher = $this->voucher(sessions: 3);

        $outcomes = $this->race(10, 'control');

        $this->assertSame(3, $this->howMany($outcomes, 'confirmed'), $this->describe($outcomes));
        $this->assertSame(7, $this->howMany($outcomes, 'saldo-insuficiente'), $this->describe($outcomes));
        $this->assertLedger($voucher, balance: 0, holds: 3);
        $this->assertReservations(confirmed: 3, byVoucher: [$voucher => 3]);
    }

    /** With two applicable vouchers the one that expires first is emptied first and the requests that lose it fall to the other, with no balance below zero (BO-08, BO-03). */
    public function test_two_vouchers_under_a_race_are_used_by_expiry_and_neither_goes_negative(): void
    {
        $slow = $this->voucher(sessions: 2, validityDays: 90);
        $fast = $this->voucher(sessions: 1, validityDays: 30);

        $outcomes = $this->race(6, 'control');

        $this->assertSame(3, $this->howMany($outcomes, 'confirmed'), $this->describe($outcomes));
        $this->assertSame(3, $this->howMany($outcomes, 'saldo-insuficiente'), $this->describe($outcomes));
        $this->assertLedger($fast, balance: 0, holds: 1);
        $this->assertLedger($slow, balance: 0, holds: 2);
        $this->assertReservations(confirmed: 3, byVoucher: [$fast => 1, $slow => 2]);
    }

    /** Without the conditional update the same race sells more sessions than the voucher has and its record no longer adds up: the tests above notice the control is missing (BO-03, BO-09). */
    public function test_without_the_conditional_update_the_sessions_are_sold_more_than_once(): void
    {
        $voucher = $this->voucher(sessions: 3);

        $outcomes = $this->race(10, 'no-control');

        $this->assertGreaterThan(3, $this->howMany($outcomes, 'confirmed'), 'Without the control more reservations than sessions must be confirmed.');
        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function () use ($voucher): void {
            $balance = (int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions');
            $holds = DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->where('type', 'hold')->count();

            $this->assertGreaterThan(3, $holds, 'Without the control more sessions are recorded as taken than the voucher had.');
            $this->assertNotSame($balance, (int) DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->sum('sessions_delta'), 'Without the control the record no longer adds up to the balance.');
        });
    }

    /** An active voucher of Ana, committed, that lasts the days from 1 March 2030. */
    private function voucher(int $sessions, int $validityDays = 90): string
    {
        $voucher = '';
        DB::transaction(function () use ($sessions, $validityDays, &$voucher): void {
            $voucher = $this->insertPurchasedVoucher($this->puraNavaja, $this->membership, $this->definition, 'active', $sessions, $validityDays);
        });

        return $voucher;
    }

    /**
     * One process per request, each for a different collaborator and hour of Tuesday 5 March 2030, all paid with a voucher of Ana.
     *
     * @return list<string>
     */
    private function race(int $requests, string $mode): array
    {
        $arguments = [];

        foreach (array_slice($this->collaborators, 0, $requests) as $position => $collaborator) {
            $start = (new DateTimeImmutable('2030-03-05 09:00:00-06:00'))->modify("+{$position} hours")->format(DATE_ATOM);
            $arguments[] = [$this->puraNavaja, $this->user, $this->degradado, $collaborator, $start, self::NOW, $mode];
        }

        return array_map(function (string $output): string {
            /** @var array{outcome: string} $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            return $decoded['outcome'];
        }, Race::run(base_path('tests/Support/Concurrency/reserve_with_voucher_worker.php'), $arguments));
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

    /** @param  list<string>  $outcomes */
    private function howMany(array $outcomes, string $outcome): int
    {
        return count(array_filter($outcomes, fn (string $each): bool => $each === $outcome));
    }

    /** @param  list<string>  $outcomes */
    private function describe(array $outcomes): string
    {
        return (string) json_encode($outcomes);
    }

    /** The balance is what is left and never below zero, the record has one hold per confirmed request and all its entries add up to the balance (BO-03, BO-09). */
    private function assertLedger(string $voucher, int $balance, int $holds): void
    {
        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function () use ($voucher, $balance, $holds): void {
            $remaining = (int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions');

            $this->assertGreaterThanOrEqual(0, $remaining);
            $this->assertSame($balance, $remaining);
            $this->assertSame($holds, DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->where('type', 'hold')->count());
            $this->assertSame($remaining, (int) DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->sum('sessions_delta'));
        });
    }

    /**
     * The refused requests left no reservation, the confirmed ones are confirmed with no deposit and each voucher is the one of as many reservations as sessions it gave.
     *
     * @param  array<string, int>  $byVoucher
     */
    private function assertReservations(int $confirmed, array $byVoucher): void
    {
        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function () use ($confirmed, $byVoucher): void {
            $this->assertSame($confirmed, DB::table('reservations')->count());
            $this->assertSame($confirmed, DB::table('reservations')->where('status', 'confirmed')->count());
            $this->assertSame(0, DB::table('payments')->count());

            foreach ($byVoucher as $voucher => $count) {
                $this->assertSame($count, DB::table('reservations')->where('purchased_voucher_id', $voucher)->count());
            }
        });
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
