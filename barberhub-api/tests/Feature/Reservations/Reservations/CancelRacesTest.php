<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * Cancellations that run at the same instant (RNF-07, CA-06, PA-07): two cancellations of the same reservation, and a cancellation against the
 * settlement of its payment. Each one is a PHP process with its own connection that runs the real use case, and the rows are committed for
 * real, so the lock of the reservation and the conditional write that marks the deposit are what keep the effect to one: one cancellation
 * in the history, one payment, marked to give back once, and no deadlock between the lock of the reservation and the one of the payment.
 */
final class CancelRacesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private const string NOW = '2030-03-01T08:01:00-06:00';

    private const string REFERENCE = 'pi_cancel_race';

    private string $user;

    private string $membership;

    /** Start from an empty database with a customer, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            [$this->user, $this->membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
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

    /** Four cancellations at once of a confirmed reservation with an approved deposit: one cancels and marks it, the others repeat, and the payment is marked once (CA-06). */
    public function test_simultaneous_cancellations_mark_the_deposit_once(): void
    {
        $reservation = $this->seedReservation('confirmed', 'approved');

        $outcomes = $this->race(array_fill(0, 4, ['cancel', $reservation, '-', 'evt']));

        $this->assertSame(array_fill(0, 4, 'cancelled:to_refund'), $outcomes);
        $this->inBarbershop(function () use ($reservation): void {
            $payment = (array) DB::table('payments')->first();
            $this->assertSame(1, DB::table('payments')->count());
            $this->assertSame(['approved', true], [$payment['status'] ?? null, $payment['refund_pending'] ?? null]);
            $this->assertSame('cancelled_by_customer', DB::table('reservations')->where('id', $reservation)->value('status'));
            $this->assertSame(1, DB::table('reservation_status_history')->where('reservation_id', $reservation)->where('to_status', 'cancelled_by_customer')->count());
        });
    }

    /**
     * The customer cancels while the notice that approves the payment arrives, and a second event of the same payment too: whichever goes first,
     * the reservation ends cancelled and the payment approved and marked to give back, because either the cancellation marks it or the settlement
     * finds the reservation cancelled and marks it (PA-07). Nobody waits on anybody: there is no deadlock.
     */
    public function test_a_cancellation_against_the_settlement_of_the_payment_gives_the_deposit_back(): void
    {
        $reservation = $this->seedReservation('temporary', 'pending');

        $outcomes = $this->race([['notice', $reservation, self::REFERENCE, 'evt_1'], ['cancel', $reservation, '-', 'evt'], ['cancel', $reservation, '-', 'evt'], ['notice', $reservation, self::REFERENCE, 'evt_2']]);

        foreach ($outcomes as $outcome) {
            $this->assertStringNotContainsString('Exception', $outcome, json_encode($outcomes) ?: '');
            $this->assertStringNotContainsString('deadlock', $outcome, json_encode($outcomes) ?: '');
        }

        $this->inBarbershop(function () use ($reservation): void {
            $payment = (array) DB::table('payments')->first();
            $this->assertSame(1, DB::table('payments')->count());
            $this->assertSame(['approved', true], [$payment['status'] ?? null, $payment['refund_pending'] ?? null]);
            $this->assertSame('cancelled_by_customer', DB::table('reservations')->where('id', $reservation)->value('status'));
            $this->assertSame(1, DB::table('reservation_status_history')->where('reservation_id', $reservation)->where('to_status', 'cancelled_by_customer')->count());
        });
    }

    /** Insert, committed, a reservation of Ana in the status given with its payment in the status given, and return the reservation. */
    private function seedReservation(string $reservationStatus, string $paymentStatus): string
    {
        $reservation = '';

        DB::transaction(function () use ($reservationStatus, $paymentStatus, &$reservation): void {
            $reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->membership, '2030-03-05 10:00:00-06:00', $reservationStatus);

            if ($reservationStatus === 'temporary') {
                DB::table('reservations')->where('id', $reservation)->update(['expires_at' => '2030-03-01 08:05:00-06:00']);
            }

            DB::table('payments')->insert([
                'id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'reservation_id' => $reservation, 'provider' => 'onvo',
                'method' => 'card', 'status' => $paymentStatus, 'amount' => 2100, 'provider_payment_id' => self::REFERENCE,
            ]);
        });

        return $reservation;
    }

    /**
     * One process per operation; what each one printed.
     *
     * @param  list<array{string, string, string, string}>  $operations  Mode, reservation, provider reference and event.
     * @return list<string>
     */
    private function race(array $operations): array
    {
        $outputs = Race::run(base_path('tests/Support/Concurrency/cancel_or_settle_worker.php'), array_map(
            fn (array $operation): array => [$operation[0], $this->puraNavaja, $this->user, $operation[1], $operation[2], $operation[3], self::NOW],
            $operations,
        ));

        return array_map(function (string $output): string {
            /** @var array{outcome: string} $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            return $decoded['outcome'];
        }, $outputs);
    }

    /**
     * Run the checks inside the barbershop, so its row-level policies let the rows through.
     *
     * @param  Closure(): void  $checks
     */
    private function inBarbershop(Closure $checks): void
    {
        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, $checks);
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users, webhook_events cascade');
    }
}
