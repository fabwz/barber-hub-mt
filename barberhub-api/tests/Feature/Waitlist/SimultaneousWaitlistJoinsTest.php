<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use Illuminate\Support\Facades\DB;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * Requests to join the waitlist at the same instant (LE-01). Each one is a PHP process with its own connection that runs the use
 * case, and the rows are committed for real, so the lock of the line of the customer is what decides: however many arrive together,
 * a customer never ends with more than 3 active rows. None of the days asked for has free spaces (nobody works on them).
 */
final class SimultaneousWaitlistJoinsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsWaitlist;

    private string $user;

    private string $membership;

    /** Start from an empty database with Pura Navaja, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            [$this->user, $this->membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
            DB::table('barbershops')->where('id', $this->puraNavaja)->update(['waitlist_enabled' => true]);
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

    /** With two active rows, two joins at once leave exactly three: one is accepted and the other is refused, never four. */
    public function test_two_joins_with_two_active_rows_leave_three(): void
    {
        DB::transaction(function (): void {
            $this->insertWaitlistEntry($this->puraNavaja, $this->membership, $this->degradado, date: '2030-03-06');
            $this->insertWaitlistEntry($this->puraNavaja, $this->membership, $this->degradado, date: '2030-03-07');
        });

        $outcomes = $this->race($this->user, ['2030-03-08', '2030-03-09']);

        $this->assertSame(['joined', 'limit'], $outcomes, json_encode($outcomes) ?: '');
        $this->assertSame(3, $this->activeRows());
    }

    /** Five first requests at once from a person with no role: one role, exactly three rows, and two refused (UP-09, LE-01). */
    public function test_five_first_joins_leave_one_role_and_three_rows(): void
    {
        $person = '';
        DB::transaction(function () use (&$person): void {
            $person = $this->insertUser('Nora Vega');
        });

        $outcomes = $this->race($person, ['2030-03-06', '2030-03-07', '2030-03-08', '2030-03-09', '2030-03-10']);

        $this->assertSame(['joined', 'joined', 'joined', 'limit', 'limit'], $outcomes, json_encode($outcomes) ?: '');
        $this->assertSame(1, DB::table('memberships')->where('user_id', $person)->where('barbershop_id', $this->puraNavaja)->count());
        $this->assertSame(3, $this->activeRows());
    }

    /**
     * One process per date, all joining the waitlist for the same service as the same person.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    private function race(string $user, array $dates): array
    {
        $outputs = Race::run(base_path('tests/Support/Concurrency/join_waitlist_worker.php'), array_map(
            fn (string $date): array => [$this->puraNavaja, $user, $this->degradado, $date],
            $dates,
        ));

        $outcomes = array_map(function (string $output): string {
            /** @var array{outcome: string} $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            return $decoded['outcome'];
        }, $outputs);
        sort($outcomes);

        return $outcomes;
    }

    /** The rows still waiting or offered, whoever owns them: the test has one customer in line. */
    private function activeRows(): int
    {
        return $this->app->make(BarbershopTransaction::class)->run(
            $this->puraNavaja,
            fn (): int => DB::table('waitlist_entries')->whereIn('status', ['waiting', 'offered'])->count(),
        );
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
