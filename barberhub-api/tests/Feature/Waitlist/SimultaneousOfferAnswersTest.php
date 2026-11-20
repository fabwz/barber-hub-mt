<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsOffers;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * Answers to the same offer at the same instant (RF-32, LE-04, LE-05, RE-01). Each contender is a PHP process with its own connection that runs
 * the real use case, and the rows are committed for real, so the lock of the collaborator and the lock of the offer are what decide: an offer
 * ends accepted with its reservation or expired with none, never both, and two acceptances at once book one reservation. Today is Friday
 * 1 March 2030, 8:00; Ana holds an offer for the 10:00 space of Daniela on Tuesday, made at 8:00 and valid until 8:15.
 */
final class SimultaneousOfferAnswersTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsOffers;
    use SeedsReservations;
    use SeedsWaitlist;

    private string $offer;

    /** Start from an empty database with Pura Navaja, the line of three and an offer to Ana, all committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedOffers();
            DB::table('reservations')->where('id', $this->bookedReservation)->update(['status' => 'cancelled_by_customer']);
            DB::table('waitlist_entries')->where('id', $this->anaEntry)->update(['status' => 'offered']);
            $this->offer = $this->insertOfferRow($this->anaEntry, $this->daniela, '10:00', '10:50');
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

    /**
     * The customer answers in the last second and the task that expires the offer fires at its deadline, at the same time. Whoever gets the lock of
     * Daniela first wins: either the offer is accepted, its reservation confirmed and the row fulfilled, and the task finds nothing to expire, or the
     * offer expires, the row goes back to waiting and the answer is `oferta-vencida`, with no reservation. Never both, in each of the rounds.
     */
    #[DataProvider('rounds')]
    public function test_accepting_and_expiring_at_the_same_time_never_both_win(int $round): void
    {
        $outputs = Race::run(base_path('tests/Support/Concurrency/answer_offer_worker.php'), [
            ['accept', $this->puraNavaja, $this->offer, $this->ana, '2030-03-01T08:14:59-06:00'],
            ['expire', $this->puraNavaja, $this->offer, $this->ana, '2030-03-01T08:15:01-06:00'],
        ]);
        $byRole = array_column(array_map($this->decode(...), $outputs), 'outcome', 'role');
        $status = $this->inBarbershop(fn (): string => (string) DB::table('waitlist_offers')->where('id', $this->offer)->value('status'));
        $reservations = $this->inBarbershop(fn (): int => DB::table('reservations')->where('customer_membership_id', $this->anaMembership)->where('status', 'confirmed')->count());
        $entry = $this->inBarbershop(fn (): string => (string) DB::table('waitlist_entries')->where('id', $this->anaEntry)->value('status'));
        $context = "Round {$round}: ".(json_encode($outputs) ?: '');

        if ($status === 'accepted') {
            $this->assertSame('ok', $byRole['accept'], $context);
            $this->assertSame('unchanged', $byRole['expire'], $context);
            $this->assertSame(1, $reservations, $context);
            $this->assertSame('fulfilled', $entry, $context);
        } else {
            $this->assertSame('expired', $status, $context);
            $this->assertSame('oferta-vencida', $byRole['accept'], $context);
            $this->assertSame('expired', $byRole['expire'], $context);
            $this->assertSame(0, $reservations, $context);
            $this->assertSame('waiting', $entry, $context);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function rounds(): iterable
    {
        foreach (range(1, 4) as $round) {
            yield "round {$round}" => [$round];
        }
    }

    /** Three acceptances of the same offer at once book one reservation: the others find the offer already accepted and answer the same (RF-32). */
    public function test_simultaneous_acceptances_book_one_reservation(): void
    {
        $outputs = array_map($this->decode(...), Race::run(base_path('tests/Support/Concurrency/answer_offer_worker.php'), array_fill(0, 3, [
            'accept', $this->puraNavaja, $this->offer, $this->ana, '2030-03-01T08:05:00-06:00',
        ])));

        $this->assertSame(['ok', 'ok', 'ok'], array_column($outputs, 'outcome'), json_encode($outputs) ?: '');
        $this->assertCount(1, array_unique(array_column($outputs, 'reservation')), json_encode($outputs) ?: '');
        $this->assertSame(1, $this->inBarbershop(fn (): int => DB::table('reservations')->where('customer_membership_id', $this->anaMembership)->where('status', 'confirmed')->count()));
        $this->assertSame('accepted', $this->inBarbershop(fn (): string => (string) DB::table('waitlist_offers')->where('id', $this->offer)->value('status')));
        $this->assertSame('fulfilled', $this->inBarbershop(fn (): string => (string) DB::table('waitlist_entries')->where('id', $this->anaEntry)->value('status')));
    }

    /** An acceptance and a rejection at once leave one answer: the loser is refused with the conflict, and the space is never both booked and offered on. */
    public function test_accepting_and_rejecting_at_the_same_time_leave_one_answer(): void
    {
        $outputs = array_map($this->decode(...), Race::run(base_path('tests/Support/Concurrency/answer_offer_worker.php'), [
            ['accept', $this->puraNavaja, $this->offer, $this->ana, '2030-03-01T08:05:00-06:00'],
            ['reject', $this->puraNavaja, $this->offer, $this->ana, '2030-03-01T08:05:00-06:00'],
        ]));
        $byRole = array_column($outputs, 'outcome', 'role');
        $status = $this->inBarbershop(fn (): string => (string) DB::table('waitlist_offers')->where('id', $this->offer)->value('status'));
        $reservations = $this->inBarbershop(fn (): int => DB::table('reservations')->where('customer_membership_id', $this->anaMembership)->where('status', 'confirmed')->count());
        $context = json_encode($outputs) ?: '';

        $this->assertSame(['ok', 'conflicto'], $status === 'accepted' ? [$byRole['accept'], $byRole['reject']] : [$byRole['reject'], $byRole['accept']], $context);
        $this->assertSame($status === 'accepted' ? 1 : 0, $reservations, $context);
    }

    /** @return array{outcome: string, role: string, reservation: string|null} */
    private function decode(string $output): array
    {
        /** @var array{outcome: string, role: string, reservation: string|null} $decoded */
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Run a read as Pura Navaja, which the row policies need.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function inBarbershop(Closure $read): mixed
    {
        return $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, $read);
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
