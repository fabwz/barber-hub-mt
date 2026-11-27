<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\AssistedClients;

use Illuminate\Support\Facades\DB;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\TestCase;

/**
 * An assisted reservation and online ones compete for the same space of Daniela at the same instant (RF-12, RE-01, RE-02). Each contender is a PHP
 * process with its own connection that runs the real use case, and the rows are committed for real: nothing is wrapped in a test transaction, so the
 * rows are removed by hand at the start and at the end. The lock of the collaborator keeps them apart, so exactly one is confirmed whoever wins and
 * nobody reaches the exclusion of the database; the assisted clients registered by the contenders that lost are rolled back with them.
 */
final class AssistedReservationRaceTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private const int ASSISTED = 10;

    private const int ONLINE = 10;

    private const string NOW = '2030-03-01T08:00:00-06:00';

    /** @var list<string> */
    private array $customers = [];

    /** Start from an empty database and seed the barbershops and the accounts of the online contenders, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            $this->customers = array_map(fn (int $number): string => $this->insertUser("Persona {$number}"), range(1, self::ONLINE));
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

    /** Ten assisted registrations and ten online bookings for the same space leave exactly one reservation, without duplicates, and no assisted client of a loser (RE-01). */
    public function test_assisted_and_online_requests_for_the_same_space_confirm_exactly_one(): void
    {
        $arguments = [];

        foreach (range(1, self::ASSISTED) as $number) {
            $arguments[] = [$this->puraNavaja, 'assisted', $this->owner, "cliente{$number}", $this->degradado, $this->daniela, self::TUESDAY_TEN, self::NOW];
        }

        foreach ($this->customers as $customer) {
            $arguments[] = [$this->puraNavaja, 'online', $customer, '-', $this->degradado, $this->daniela, self::TUESDAY_TEN, self::NOW];
        }

        $results = array_map(function (string $output): array {
            /** @var array{kind: string, outcome: string, collisions: int} $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            return $decoded;
        }, Race::run(base_path('tests/Support/Concurrency/assisted_space_worker.php'), $arguments));

        $confirmed = array_values(array_filter($results, fn (array $result): bool => $result['outcome'] === 'confirmed'));
        $this->assertCount(1, $confirmed, 'Exactly one request must be confirmed.');
        $this->assertCount(self::ASSISTED + self::ONLINE - 1, array_filter($results, fn (array $result): bool => $result['outcome'] === 'espacio-no-disponible'), 'The others must receive espacio-no-disponible.');
        $this->assertSame(0, array_sum(array_column($results, 'collisions')), 'With the lock no request may reach the exclusion of the database.');

        $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, function () use ($confirmed): void {
            $reservations = DB::table('reservations')->get();
            $this->assertCount(1, $reservations);
            $reservation = $reservations->first();
            $this->assertNotNull($reservation);
            $this->assertSame([$this->daniela, 'confirmed'], [$reservation->collaborator_id, $reservation->status]);
            $this->assertSame(2, DB::table('reservation_status_history')->count());

            if ($confirmed[0]['kind'] === 'assisted') {
                $this->assertNull($reservation->customer_membership_id);
                $this->assertNotNull($reservation->assisted_client_id);
                $this->assertSame(1, DB::table('assisted_clients')->count(), 'Only the client of the winner may stay.');
                $this->assertSame(0, DB::table('memberships')->where('role', 'customer')->count());
            } else {
                $this->assertNull($reservation->assisted_client_id);
                $this->assertSame(0, DB::table('assisted_clients')->count(), 'No assisted client may stay when an online request won.');
                $this->assertSame(1, DB::table('memberships')->where('role', 'customer')->count());
            }
        });
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
