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
 * Requests at the same instant over a space that was freed (RF-31, DI-05, RE-01). Each one is a PHP process with its own connection that runs the
 * real use case, and the rows are committed for real, so the lock of the collaborator is what decides: however many releases arrive together the
 * space receives one offer, and a customer who books it at the same time never ends with it together with the offer. Today is Friday 1 March 2030,
 * 8:00, and the reservation of 10:00 on Tuesday is already cancelled, so its space is free.
 */
final class SimultaneousOffersTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsOffers;
    use SeedsReservations;
    use SeedsWaitlist;

    private const string NOW = '2030-03-01T08:00:00-06:00';

    private string $stranger;

    /** Start from an empty database with Pura Navaja, the line of three and the space of Daniela free, all committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedOffers();
            [$this->stranger] = $this->insertCustomer($this->puraNavaja, 'Nora Vega');
            DB::table('reservations')->where('id', $this->bookedReservation)->update(['status' => 'cancelled_by_customer']);
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

    /** Two releases of the same space at the same instant leave exactly one offer: the second finds the space held by the first (RF-31, DI-05). */
    public function test_two_simultaneous_releases_make_one_offer(): void
    {
        $outcomes = $this->release(2);

        $this->assertSame(['none', 'offered'], $outcomes, json_encode($outcomes) ?: '');
        $this->assertSame(1, $this->offerCount());
        $this->assertSame(['offered', 'waiting', 'waiting'], [$this->entryStatusOf($this->anaEntry), $this->entryStatusOf($this->luisEntry), $this->entryStatusOf($this->beaEntry)]);
    }

    /** Six releases at once, as a burst of retries and duplicates of the same event, still make one offer, to the first in the line. */
    public function test_six_simultaneous_releases_make_one_offer_to_the_first(): void
    {
        $outcomes = $this->release(6);

        $this->assertSame(['none', 'none', 'none', 'none', 'none', 'offered'], $outcomes, json_encode($outcomes) ?: '');
        $this->assertSame([$this->anaEntry], $this->offeredEntries());
        $this->assertSame(1, $this->offerCount());
    }

    /**
     * A release and a booking of the same space at the same instant: whoever gets the lock of Daniela first wins, and the other finds it taken.
     * Either the offer holds the space and the booking is refused with the usual conflict, or the booking is confirmed and no offer is made, and
     * never both, in each of the rounds (RE-01, DI-05). Each round starts from a fresh database.
     */
    #[DataProvider('rounds')]
    public function test_a_release_and_a_booking_of_the_same_space_never_both_win(int $round): void
    {
        $outputs = array_map($this->decode(...), Race::run(base_path('tests/Support/Concurrency/space_contender_worker.php'), [
            ['release', $this->puraNavaja, $this->daniela, '2030-03-05T10:00:00-06:00', '2030-03-05T10:50:00-06:00', self::NOW],
            ['book', $this->puraNavaja, $this->daniela, '2030-03-05T10:00:00-06:00', '2030-03-05T10:50:00-06:00', self::NOW, $this->stranger, $this->degradado],
        ]));
        $byRole = array_column($outputs, 'outcome', 'role');

        $offers = $this->offerCount();
        $booked = $this->strangerReservations();

        $this->assertSame(1, $offers + $booked, "Round {$round}: the space ended with {$offers} offers and {$booked} reservations: ".(json_encode($outputs) ?: ''));
        $this->assertSame($offers === 1 ? 'offered' : 'none', $byRole['release'], json_encode($outputs) ?: '');
        $this->assertSame($booked === 1 ? 'confirmed' : 'espacio-no-disponible', $byRole['book'], json_encode($outputs) ?: '');
    }

    /** @return iterable<string, array{int}> */
    public static function rounds(): iterable
    {
        foreach (range(1, 4) as $round) {
            yield "round {$round}" => [$round];
        }
    }

    /** Several customers who book the offered space at once are all refused: the offer holds it (DI-05). */
    public function test_simultaneous_bookings_of_an_offered_space_are_all_refused(): void
    {
        $this->release(1);
        $this->assertSame(1, $this->offerCount());

        $outputs = Race::run(base_path('tests/Support/Concurrency/book_space_worker.php'), array_map(
            fn (): array => [$this->puraNavaja, $this->stranger, $this->degradado, $this->daniela, '2030-03-05T10:00:00-06:00', self::NOW, 'lock'],
            range(1, 3),
        ));

        $outcomes = array_map(fn (string $output): string => $this->decode($output)['outcome'], $outputs);
        $this->assertSame(['espacio-no-disponible', 'espacio-no-disponible', 'espacio-no-disponible'], $outcomes);
        $this->assertSame(0, $this->strangerReservations());
        $this->assertSame(1, $this->offerCount());
    }

    /**
     * The releases of a space, at the same instant.
     *
     * @return list<string>
     */
    private function release(int $processes): array
    {
        $outputs = Race::run(base_path('tests/Support/Concurrency/release_space_worker.php'), array_fill(0, $processes, $this->releaseArguments()));
        $outcomes = array_map(fn (string $output): string => $this->decode($output)['outcome'], $outputs);
        sort($outcomes);

        return $outcomes;
    }

    /**
     * The arguments of a release of the space of the cancelled reservation.
     *
     * @return list<string>
     */
    private function releaseArguments(): array
    {
        return [$this->puraNavaja, $this->daniela, '2030-03-05T10:00:00-06:00', '2030-03-05T10:50:00-06:00', self::NOW];
    }

    /** @return array{outcome: string, role?: string, entry?: string|null} */
    private function decode(string $output): array
    {
        /** @var array{outcome: string, role?: string, entry?: string|null} $decoded */
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function offerCount(): int
    {
        return $this->inBarbershop(fn (): int => DB::table('waitlist_offers')->where('status', 'pending')->count());
    }

    private function strangerReservations(): int
    {
        return $this->inBarbershop(fn (): int => DB::table('reservations')->where('customer_membership_id', '!=', $this->bookerMembership)->whereIn('status', ['confirmed', 'temporary'])->count());
    }

    /** @return list<string> */
    private function offeredEntries(): array
    {
        return $this->inBarbershop(fn (): array => array_values(array_map(strval(...), DB::table('waitlist_entries')->where('status', 'offered')->pluck('id')->all())));
    }

    private function entryStatusOf(string $entryId): string
    {
        return $this->inBarbershop(fn (): string => (string) DB::table('waitlist_entries')->where('id', $entryId)->value('status'));
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
