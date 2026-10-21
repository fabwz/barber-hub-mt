<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Reservations\Reservations\Application\UseCases\ListBlockedIntervals;
use Src\Reservations\Reservations\Infrastructure\Persistence\Queries\EloquentBlockedIntervalReader;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class BlockedIntervalReaderTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private string $kevin;

    private string $daniela;

    private string $haircut;

    private ListBlockedIntervals $blocked;

    /** Two barbershops; the first has two collaborators, a service and a customer, and the reader works on it. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->scopeQueriesTo($this->puraNavaja);
        $this->kevin = $this->insertCollaborator($this->puraNavaja);
        $this->daniela = $this->insertCollaborator($this->puraNavaja);
        $this->haircut = $this->insertService($this->puraNavaja);
        $this->blocked = new ListBlockedIntervals(new EloquentBlockedIntervalReader(new FixedBarbershopContext(new BarbershopId($this->puraNavaja))));
    }

    /** Temporary and confirmed reservations block the space; the ones that ended in a final status do not (DI-01, DI-05). */
    public function test_only_temporary_and_confirmed_reservations_block(): void
    {
        $this->insertReservation($this->kevin, 'temporary', '2030-03-05 09:00');
        $this->insertReservation($this->kevin, 'confirmed', '2030-03-05 10:00');
        foreach (['completed', 'no_show', 'cancelled_by_customer', 'cancelled_by_barbershop', 'expired'] as $index => $status) {
            $this->insertReservation($this->kevin, $status, sprintf('2030-03-05 %02d:00', 11 + $index));
        }

        $intervals = $this->blocked->execute([$this->kevin], $this->day('2030-03-05'))[$this->kevin];

        $this->assertSame(
            [['09:00', '09:50'], ['10:00', '10:50']],
            array_map(fn (TimeInterval $interval): array => [$this->local($interval->start), $this->local($interval->end)], $intervals),
        );
    }

    /** The reservation being rescheduled can be left out, and only that one, so it does not block its own move (RE-08). */
    public function test_a_reservation_can_be_left_out(): void
    {
        $moving = $this->insertReservation($this->kevin, 'confirmed', '2030-03-05 09:00');
        $this->insertReservation($this->kevin, 'confirmed', '2030-03-05 10:00');

        $intervals = $this->blocked->execute([$this->kevin], $this->day('2030-03-05'), $moving)[$this->kevin];

        $this->assertSame([['10:00', '10:50']], array_map(fn (TimeInterval $interval): array => [$this->local($interval->start), $this->local($interval->end)], $intervals));
    }

    /** The end of a block is the end of the cleaning, not of the service. */
    public function test_a_block_ends_with_the_cleaning(): void
    {
        $this->insertReservation($this->kevin, 'confirmed', '2030-03-05 09:00');

        $interval = $this->blocked->execute([$this->kevin], $this->day('2030-03-05'))[$this->kevin][0];

        $this->assertSame(50, $interval->duration()->minutes);
    }

    /** Reservations are grouped by collaborator, and one without any is absent. */
    public function test_groups_the_intervals_by_collaborator(): void
    {
        $this->insertReservation($this->kevin, 'confirmed', '2030-03-05 09:00');
        $this->insertReservation($this->daniela, 'confirmed', '2030-03-05 09:00');
        $this->insertReservation($this->daniela, 'confirmed', '2030-03-05 15:00');

        $intervals = $this->blocked->execute([$this->kevin, $this->daniela], $this->day('2030-03-05'));

        $this->assertCount(1, $intervals[$this->kevin]);
        $this->assertCount(2, $intervals[$this->daniela]);
        $this->assertSame([], $this->blocked->execute([$this->daniela], $this->day('2030-03-06')));
    }

    /** Only the collaborators asked for are read. */
    public function test_reads_only_the_collaborators_asked_for(): void
    {
        $this->insertReservation($this->kevin, 'confirmed', '2030-03-05 09:00');
        $this->insertReservation($this->daniela, 'confirmed', '2030-03-05 09:00');

        $this->assertSame([$this->kevin], array_keys($this->blocked->execute([$this->kevin], $this->day('2030-03-05'))));
        $this->assertSame([], $this->blocked->execute([], $this->day('2030-03-05')));
    }

    /** A reservation belongs to the window if it shares an instant with it: crossing a border counts, touching it does not. */
    public function test_the_window_edges(): void
    {
        [$crossesStart, $crossesEnd, $before, $startsAtEnd, $endsAtStart] = [$this->kevin, $this->daniela, $this->insertCollaborator($this->puraNavaja), $this->insertCollaborator($this->puraNavaja), $this->insertCollaborator($this->puraNavaja)];
        $this->insertReservation($crossesStart, 'confirmed', '2030-03-04 23:30'); // blocked until 00:20 of the 5th
        $this->insertReservation($crossesEnd, 'confirmed', '2030-03-05 23:30'); // blocked until 00:20 of the 6th
        $this->insertReservation($before, 'confirmed', '2030-03-04 22:00'); // blocked until 22:50 of the 4th
        $this->insertReservation($startsAtEnd, 'confirmed', '2030-03-06 00:00'); // starts when the window ends
        $this->insertReservation($endsAtStart, 'confirmed', '2030-03-04 23:10'); // blocked until 00:00 exactly

        $intervals = $this->blocked->execute([$crossesStart, $crossesEnd, $before, $startsAtEnd, $endsAtStart], $this->day('2030-03-05'));

        $this->assertEqualsCanonicalizing([$crossesStart, $crossesEnd], array_keys($intervals));
    }

    /** The reservations of another barbershop never block, even if the identifiers of its collaborators are asked for. */
    public function test_reservations_of_another_barbershop_are_not_read(): void
    {
        $this->insertReservation($this->kevin, 'confirmed', '2030-03-05 09:00');
        $other = new ListBlockedIntervals(new EloquentBlockedIntervalReader(new FixedBarbershopContext(new BarbershopId($this->fadeLab))));

        $this->assertSame([], $other->execute([$this->kevin], $this->day('2030-03-05')));
    }

    /** Any number of collaborators is read with a single query. */
    public function test_reads_with_a_single_query(): void
    {
        $this->insertReservation($this->kevin, 'confirmed', '2030-03-05 09:00');
        $this->insertReservation($this->daniela, 'confirmed', '2030-03-05 09:00');

        DB::enableQueryLog();
        $this->blocked->execute([$this->kevin, $this->daniela], $this->day('2030-03-05'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    private function day(string $date): TimeInterval
    {
        $start = self::at("{$date} 00:00");

        return TimeInterval::between($start, $start->modify('+1 day'));
    }

    private function local(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('America/Costa_Rica'))->format('H:i');
    }

    /** Insert a reservation of a new customer (one customer never has two confirmed reservations at once, RE-06), with the 5 minutes of cleaning after the service. */
    private function insertReservation(string $collaboratorId, string $status, string $startsAt, int $duration = 45): string
    {
        $start = self::at($startsAt);
        $id = (string) Str::uuid7();

        DB::table('reservations')->insert([
            'id' => $id,
            'barbershop_id' => $this->puraNavaja,
            'collaborator_id' => $collaboratorId,
            'service_id' => $this->haircut,
            'customer_membership_id' => $this->insertCustomer($this->puraNavaja),
            'status' => $status,
            'starts_at' => $start,
            'ends_at' => $start->modify("+{$duration} minutes"),
            'blocked_until' => $start->modify('+'.($duration + 5).' minutes'),
            'price' => 8500,
            'duration_minutes' => $duration,
            'cancellation_deadline_hours' => 4,
        ]);

        return $id;
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    private function insertCollaborator(string $barbershopId): string
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => 'Colaborador', 'email' => "{$userId}@barberhub.test", 'password' => 'hash']);
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $userId]);

        return $id;
    }

    private function insertCustomer(string $barbershopId): string
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => 'Ana Rojas', 'email' => "{$userId}@barberhub.test", 'password' => 'hash']);
        DB::table('memberships')->insert(['id' => $id, 'user_id' => $userId, 'barbershop_id' => $barbershopId, 'role' => 'customer']);

        return $id;
    }

    private function insertService(string $barbershopId): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => 'Degradado', 'duration_minutes' => 45, 'price' => 8500]);

        return $id;
    }
}
