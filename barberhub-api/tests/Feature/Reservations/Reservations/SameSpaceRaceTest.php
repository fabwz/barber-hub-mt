<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Illuminate\Support\Facades\DB;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\TestCase;

/**
 * Fifty people ask for the same space of Daniela at the same instant (RNF-05, RE-01, RE-02). Each one is a PHP process with
 * its own connection that runs the real use case of the booking, and the rows are committed for real: nothing is wrapped in
 * a test transaction, so the rows are removed by hand at the start and at the end.
 *
 * The lock of the collaborator is the first barrier and the exclusion of the table the second (ADR 0009). Since the exclusion
 * alone would also leave one reservation, the test counts how many requests got as far as the exclusion: with the lock none
 * does, because the check made after taking it sees the space taken; without it some do, which proves the test notices the
 * lock is missing.
 */
final class SameSpaceRaceTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private const int REQUESTS = 50;

    private const string NOW = '2030-03-01T08:00:00-06:00';

    /** @var list<string> */
    private array $people = [];

    /** Start from an empty database and seed the barbershops and fifty accounts without any role, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            $this->people = array_map(fn (int $number): string => $this->insertUser("Persona {$number}"), range(1, self::REQUESTS));
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

    /** With the lock one request is confirmed, the other 49 get `espacio-no-disponible` before reaching the exclusion and leave no customer role (RE-01, RE-02, UP-09). */
    public function test_fifty_simultaneous_requests_for_the_same_space_confirm_exactly_one(): void
    {
        $results = $this->race('lock');

        $this->assertSame(1, $this->countOutcome($results, 'confirmed'), 'Exactly one request must be confirmed.');
        $this->assertSame(self::REQUESTS - 1, $this->countOutcome($results, 'espacio-no-disponible'), 'The other requests must receive espacio-no-disponible.');
        $this->assertSame(0, array_sum(array_column($results, 'collisions')), 'With the lock no request may reach the exclusion of the database.');
        $this->assertOnlyTheWinnerHasAReservation($results);
    }

    /** Without the lock the exclusion still leaves one reservation, but requests do reach it: the test notices the lock is missing. */
    public function test_without_the_lock_requests_reach_the_exclusion_of_the_database(): void
    {
        $results = $this->race('no-lock');

        $this->assertSame(1, $this->countOutcome($results, 'confirmed'), 'The exclusion of the database must still leave exactly one reservation.');
        $this->assertSame(self::REQUESTS - 1, $this->countOutcome($results, 'espacio-no-disponible'));
        $this->assertGreaterThan(0, array_sum(array_column($results, 'collisions')), 'Without the lock some request must reach the exclusion of the database.');
        $this->assertOnlyTheWinnerHasAReservation($results);
    }

    /**
     * Launch one process per person and read what each printed.
     *
     * @return list<array{outcome: string, collisions: int}>
     */
    private function race(string $mode): array
    {
        $outputs = Race::run(base_path('tests/Support/Concurrency/book_space_worker.php'), array_map(
            fn (string $person): array => [$this->puraNavaja, $person, $this->degradado, $this->daniela, self::TUESDAY_TEN, self::NOW, $mode],
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
     * Only one reservation exists for the whole barbershop, so none is duplicated or overlapped; it is the winner's, it has
     * its two history entries and the winner is the only person who got a customer role.
     *
     * @param  list<array{outcome: string, collisions: int}>  $results
     */
    private function assertOnlyTheWinnerHasAReservation(array $results): void
    {
        $position = array_find_key($results, fn (array $result): bool => $result['outcome'] === 'confirmed');
        $winner = $this->people[$position] ?? $this->fail('Nobody was confirmed.');

        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function () use ($winner): void {
            $reservations = DB::table('reservations')->get();
            $this->assertCount(1, $reservations);
            $reservation = $reservations->first();
            $this->assertNotNull($reservation);
            $this->assertSame([$this->daniela, 'confirmed'], [$reservation->collaborator_id, $reservation->status]);
            $this->assertSame(2, DB::table('reservation_status_history')->count());

            $customers = DB::table('memberships')->where('role', 'customer')->pluck('user_id')->all();
            $this->assertSame([$winner], $customers, 'The requests that lost must not leave a customer role (UP-09).');
            $this->assertSame($customers[0], DB::table('memberships')->where('id', $reservation->customer_membership_id)->value('user_id'));
        });
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
