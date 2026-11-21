<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Illuminate\Support\Facades\DB;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * Several people ask for "any available" for the space of 10:00 at the same instant (RE-01, RE-02, DI-04). Each one is a PHP
 * process with its own connection that runs the real use case, and the rows are committed for real, so the test removes them by
 * hand at the start and at the end.
 *
 * The assignment happens inside the transaction, after the lock of every candidate in the order of their identifiers: the
 * requests are decided one after the other, each one sees what the previous committed, and nobody is booked twice nor two people
 * for the same barber. The exclusion of the table is the second barrier, so the test also counts how many requests reached it:
 * with the locks none does; without them some do, which proves the test notices a missing lock.
 */
final class AnyAvailableRaceTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private const string NOW = '2030-03-01T08:00:00-06:00';

    /** @var list<string> */
    private array $people = [];

    /** Start from an empty database and seed the barbershops, the customer who holds Kevin's reservation and the people, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
    }

    /** Remove what the processes committed, even when the test failed. */
    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    /** The test commits its rows, so it does not open the transaction that rolls them back. */
    public function beginDatabaseTransaction(): void {}

    /** Kevin is busy at 10:00, so only Daniela is free: two simultaneous "any" requests leave one reservation and one refusal (RE-01, RE-02). */
    public function test_two_any_requests_for_the_only_free_space_confirm_one(): void
    {
        $results = $this->raceWithKevinBusy(2, 'lock');

        $this->assertSame(1, $this->countOutcome($results, 'confirmed'));
        $this->assertSame(1, $this->countOutcome($results, 'espacio-no-disponible'));
        $this->assertSame(0, array_sum(array_column($results, 'collisions')), 'With the locks no request may reach the exclusion of the database.');
        $this->assertOneReservationPerBarberAt10();
    }

    /** With twenty requests the result is the same: the one winner is Daniela and the 19 others get `espacio-no-disponible`. */
    public function test_twenty_any_requests_for_the_only_free_space_confirm_one(): void
    {
        $results = $this->raceWithKevinBusy(20, 'lock');

        $this->assertSame(1, $this->countOutcome($results, 'confirmed'));
        $this->assertSame(19, $this->countOutcome($results, 'espacio-no-disponible'));
        $this->assertSame(0, array_sum(array_column($results, 'collisions')));
        $this->assertOneReservationPerBarberAt10();
    }

    /** Both barbers are free: ten requests fill both with different barbers and the other eight are refused, never a double reservation. */
    public function test_any_requests_for_two_free_barbers_confirm_two_different_ones(): void
    {
        $this->seedRace(10);
        $results = $this->race('lock');

        $this->assertSame(2, $this->countOutcome($results, 'confirmed'));
        $this->assertSame(8, $this->countOutcome($results, 'espacio-no-disponible'));
        $this->assertSame(0, array_sum(array_column($results, 'collisions')));
        $this->assertOneReservationPerBarberAt10();
    }

    /** Without the locks the exclusion of the database still leaves one reservation, but requests do reach it: the test notices the lock is missing. */
    public function test_without_the_locks_requests_reach_the_exclusion_of_the_database(): void
    {
        $results = $this->raceWithKevinBusy(20, 'no-lock');

        $this->assertSame(1, $this->countOutcome($results, 'confirmed'));
        $this->assertSame(19, $this->countOutcome($results, 'espacio-no-disponible'));
        $this->assertGreaterThan(0, array_sum(array_column($results, 'collisions')), 'Without the locks some request must reach the exclusion of the database.');
        $this->assertOneReservationPerBarberAt10();
    }

    /**
     * Seed Kevin busy at 10:00 with a customer of his own and run the race.
     *
     * @return list<array{outcome: string, collisions: int}>
     */
    private function raceWithKevinBusy(int $requests, string $mode): array
    {
        $this->seedRace($requests, kevinBusy: true);

        return $this->race($mode);
    }

    /** Seed the barbershops and the people; with `$kevinBusy`, Kevin already has a confirmed reservation at 10:00. */
    private function seedRace(int $requests, bool $kevinBusy = false): void
    {
        DB::transaction(function () use ($requests, $kevinBusy): void {
            $this->seedBookableBarbershops();
            $this->people = array_map(fn (int $number): string => $this->insertUser("Persona {$number}"), range(1, $requests));

            if ($kevinBusy) {
                [, $membership] = $this->insertCustomer($this->puraNavaja, 'Marta Solís');
                $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $membership, self::TUESDAY_TEN);
            }
        });
    }

    /**
     * Launch one process per person asking for "any available" and read what each printed.
     *
     * @return list<array{outcome: string, collisions: int}>
     */
    private function race(string $mode): array
    {
        $outputs = Race::run(base_path('tests/Support/Concurrency/book_space_worker.php'), array_map(
            fn (string $person): array => [$this->puraNavaja, $person, $this->degradado, 'any', self::TUESDAY_TEN, self::NOW, $mode],
            $this->people,
        ));

        return array_map(function (string $output): array {
            /** @var array{outcome: string, collisions: int} $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            return $decoded;
        }, $outputs);
    }

    /**
     * How many requests ended with an outcome.
     *
     * @param  list<array{outcome: string, collisions: int}>  $results
     */
    private function countOutcome(array $results, string $outcome): int
    {
        return count(array_filter($results, fn (array $result): bool => $result['outcome'] === $outcome));
    }

    /**
     * Each barber has exactly one reservation at 10:00 once the race is over, so none is duplicated; the barbers that have one are
     * those of the barbershop, and all are confirmed.
     */
    private function assertOneReservationPerBarberAt10(): void
    {
        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function (): void {
            $rows = DB::table('reservations')->where('starts_at', '2030-03-05 10:00:00-06')->get();
            $barbers = $rows->pluck('collaborator_id')->all();

            $this->assertSame(array_unique($barbers), $barbers, 'A barber must not have two reservations at 10:00.');
            $this->assertEqualsCanonicalizing([$this->daniela, $this->kevin], array_slice($barbers, 0, 2));
            $this->assertCount(2, $rows);
            $this->assertSame(['confirmed'], $rows->pluck('status')->unique()->values()->all());
        });
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
