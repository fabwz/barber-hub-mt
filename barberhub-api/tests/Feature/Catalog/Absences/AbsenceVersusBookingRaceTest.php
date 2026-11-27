<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Absences;

use Illuminate\Support\Facades\DB;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Concurrency\Race;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\TestCase;

/**
 * The owner blocks 14:00 to 16:00 of Daniela while customers book spaces inside that range, all at the same instant, each one a PHP
 * process with its own connection running the real use case, with the rows committed for real (RE-01, CO-06, DI-01). The absence takes
 * the same lock of the collaborator the bookings take, so each of the two decides alone: either the absence is saved and every booking
 * got `espacio-no-disponible` or was never made, or the bookings that won are listed and the absence is refused. What can never happen is
 * a reservation inside a saved absence. Several rounds, one per Tuesday, so both orders of arrival show up.
 */
final class AbsenceVersusBookingRaceTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private const array TUESDAYS = ['2030-03-05', '2030-03-12', '2030-03-19', '2030-03-26', '2030-04-02', '2030-04-09'];

    private const array STARTS = ['14:00', '14:15', '14:30', '15:00', '15:15'];

    private const string NOW = '2030-03-01T08:00:00-06:00';

    /** @var list<string> */
    private array $people = [];

    /** Start from an empty database and seed the barbershops and a person for each contender, committed. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        DB::transaction(function (): void {
            $this->seedBookableBarbershops();
            $this->people = array_map(fn (int $number): string => $this->insertUser("Persona {$number}"), range(1, count(self::STARTS)));
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

    /** Whatever the order of arrival, no reservation ends up inside a saved absence, and each outcome is one the rules allow. */
    public function test_a_reservation_arriving_with_the_absence_never_ends_up_inside_it(): void
    {
        $blocked = 0;
        $refused = 0;

        foreach (self::TUESDAYS as $date) {
            $arguments = [['block', $this->puraNavaja, $this->daniela, $date, '14:00', '16:00', self::NOW]];

            foreach (self::STARTS as $position => $start) {
                $arguments[] = ['book', $this->puraNavaja, $this->people[$position], $this->degradado, $this->daniela, "{$date}T{$start}:00-06:00", self::NOW];
            }

            $outcomes = array_map(fn (string $output): string => $this->outcome($output), Race::run(base_path('tests/Support/Concurrency/block_or_book_worker.php'), $arguments));
            $absence = array_shift($outcomes);
            $this->assertContains($absence, ['blocked', 'ausencia-con-reservas'], "Unexpected outcome of the absence: {$absence}");

            foreach ($outcomes as $outcome) {
                $this->assertContains($outcome, ['booked', 'espacio-no-disponible'], "Unexpected outcome of a booking: {$outcome}");
            }

            $this->assertNothingInsideAnAbsence($date);

            if ($absence === 'blocked') {
                $blocked++;
                $this->assertSame(0, $this->bookedInside($date), 'A reservation was left inside the absence that was saved.');
            } else {
                $refused++;
                $this->assertGreaterThan(0, $this->bookedInside($date), 'The absence was refused but no reservation stands inside it.');
            }
        }

        $this->assertSame(count(self::TUESDAYS), $blocked + $refused);
    }

    /** Read what a process printed. */
    private function outcome(string $output): string
    {
        /** @var array{outcome: string} $decoded */
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return $decoded['outcome'];
    }

    /** Count the live reservations of Daniela that hold time inside the block of that day. */
    private function bookedInside(string $date): int
    {
        return $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, fn (): int => DB::table('reservations')
            ->where('collaborator_id', $this->daniela)
            ->whereIn('status', ['confirmed', 'temporary'])
            ->where('starts_at', '<', "{$date} 16:00:00-06")
            ->where('blocked_until', '>', "{$date} 14:00:00-06")
            ->count());
    }

    /** No reservation of the barbershop overlaps any absence of the same collaborator: the invariant itself, read from the tables. */
    private function assertNothingInsideAnAbsence(string $date): void
    {
        $overlaps = $this->app->make(BarbershopTransaction::class)->run($this->puraNavaja, fn (): int => DB::table('reservations as r')
            ->join('collaborator_absences as a', 'a.collaborator_id', '=', 'r.collaborator_id')
            ->whereIn('r.status', ['confirmed', 'temporary'])
            ->whereColumn('r.starts_at', '<', 'a.ends_at')
            ->whereColumn('r.blocked_until', '>', 'a.starts_at')
            ->whereDate('r.starts_at', $date)
            ->count());

        $this->assertSame(0, $overlaps, "A reservation overlaps an absence on {$date}.");
    }

    /** Empty the tables the test fills; the owner connection is not bound by the row-level policies when truncating. */
    private function clean(): void
    {
        DB::connection('pgsql_admin')->statement('truncate table barbershops, users cascade');
    }
}
