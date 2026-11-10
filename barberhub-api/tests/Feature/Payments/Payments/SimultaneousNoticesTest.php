<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Payments\Payments\Application\DTOs\NoticeResult;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * Notices of the same payment that arrive at the same instant (RNF-07, PA-05, PA-06). Each one is a PHP process with its own
 * connection that runs the real use case, and the rows are committed for real, so the locks of the payment and of the
 * reservation and the unique key of the record are what keeps the effect to one: one confirmed reservation, one record per
 * event, and no payment wrongly marked to give back because a second confirmation found the reservation already confirmed.
 */
final class SimultaneousNoticesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private const string NOW = '2030-03-01T08:01:00-06:00';

    private const string REFERENCE = 'pi_race_1';

    private string $payment;

    private string $reservation;

    /** Start from an empty database with a temporary reservation and its pending payment, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            [, $membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
            $this->reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $membership, '2030-03-05 10:00:00-06:00', 'temporary');
            $this->payment = (string) Str::uuid7();
            $this->scopeQueriesTo($this->puraNavaja);
            DB::table('reservations')->where('id', $this->reservation)->update(['expires_at' => '2030-03-01 08:05:00-06:00']);
            DB::table('payments')->insert([
                'id' => $this->payment, 'barbershop_id' => $this->puraNavaja, 'reservation_id' => $this->reservation, 'provider' => 'onvo',
                'method' => 'card', 'status' => 'pending', 'amount' => 2100, 'provider_payment_id' => self::REFERENCE,
            ]);
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

    /** Twelve copies of one event at once: one is applied, the rest are duplicates, and the reservation is confirmed once. */
    public function test_simultaneous_copies_of_the_same_event_are_applied_once(): void
    {
        $outcomes = $this->race(array_fill(0, 12, 'evt_same'));

        $this->assertSame(1, count(array_filter($outcomes, fn (string $outcome): bool => $outcome === NoticeResult::APPLIED)), json_encode($outcomes) ?: '');
        $this->assertSame(11, count(array_filter($outcomes, fn (string $outcome): bool => $outcome === NoticeResult::DUPLICATE)), json_encode($outcomes) ?: '');
        $this->assertSettledOnce(1);
    }

    /** Different events of the same payment at once: the lock makes them run in turn, so the second finds the payment settled and nothing is applied twice. */
    public function test_simultaneous_different_events_of_the_same_payment_confirm_once(): void
    {
        $outcomes = $this->race(['evt_1', 'evt_2', 'evt_3', 'evt_4', 'evt_5', 'evt_6']);

        $this->assertSame(array_fill(0, 6, NoticeResult::APPLIED), $outcomes);
        $this->assertSettledOnce(6);
    }

    /**
     * One process per event; what each one printed.
     *
     * @param  list<string>  $events
     * @return list<string>
     */
    private function race(array $events): array
    {
        $outputs = Race::run(base_path('tests/Support/Concurrency/apply_notice_worker.php'), array_map(
            fn (string $event): array => [self::REFERENCE, $event, self::NOW],
            $events,
        ));

        return array_map(function (string $output): string {
            /** @var array{outcome: string} $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            return $decoded['outcome'];
        }, $outputs);
    }

    /** One payment, approved and not marked to give back; the reservation confirmed once; one record per distinct event. */
    private function assertSettledOnce(int $records): void
    {
        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function (): void {
            $this->assertSame(1, DB::table('payments')->count());
            $payment = DB::table('payments')->first();
            $this->assertNotNull($payment);
            $this->assertSame(['approved', false], [$payment->status, $payment->refund_pending]);
            $this->assertSame('confirmed', DB::table('reservations')->where('id', $this->reservation)->value('status'));
            $this->assertSame(1, DB::table('reservation_status_history')->where('reservation_id', $this->reservation)->where('to_status', 'confirmed')->count());
        });

        $this->assertSame($records, DB::table('webhook_events')->count());
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users, webhook_events cascade');
    }
}
