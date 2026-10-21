<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Reservations\Reservations\Infrastructure\Persistence\Repositories\EloquentReservationRepository;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class EloquentReservationRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private EloquentReservationRepository $repository;

    private string $kevin;

    private string $haircut;

    private string $ana;

    private string $anaUser;

    /** Two barbershops and a repository that works on the first one, which has a collaborator, a service and a customer. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->repository = new EloquentReservationRepository(new FixedBarbershopContext(new BarbershopId($this->puraNavaja)));
        $this->scopeQueriesTo($this->puraNavaja);
        $this->kevin = $this->insertCollaborator($this->puraNavaja);
        $this->haircut = $this->insertService($this->puraNavaja);
        [$this->ana, $this->anaUser] = $this->insertCustomer($this->puraNavaja);
    }

    /** The identifiers are time-ordered UUIDs. */
    public function test_next_id_is_a_version_7_uuid(): void
    {
        $this->assertTrue(Str::isUuid($this->repository->nextId()->value, 7));
        $this->assertNotSame($this->repository->nextId()->value, $this->repository->nextId()->value);
    }

    /** A saved reservation is read back unchanged, with the copies of the service and the block. */
    public function test_a_saved_reservation_is_read_back_unchanged(): void
    {
        $reservation = $this->reservation('2030-03-04 10:00');
        $this->repository->save($reservation);

        $found = $this->repository->find($reservation->id);

        $this->assertNotNull($found);
        $this->assertSame($reservation->id->value, $found->id->value);
        $this->assertSame($this->kevin, $found->collaboratorId->value);
        $this->assertSame($this->haircut, $found->serviceId->value);
        $this->assertSame($this->ana, $found->customerMembershipId->value);
        $this->assertSame(ReservationStatus::Temporary, $found->status);
        $this->assertSame(self::at('2030-03-04 10:00')->getTimestamp(), $found->interval->start->getTimestamp());
        $this->assertSame(self::at('2030-03-04 10:45')->getTimestamp(), $found->interval->end->getTimestamp());
        $this->assertSame(self::at('2030-03-04 10:50')->getTimestamp(), $found->blockedUntil->getTimestamp());
        $this->assertSame(8500, $found->price->colones);
        $this->assertSame(45, $found->duration->minutes);
        $this->assertSame(4, $found->cancellationDeadlineHours);
        $this->assertSame(0, $found->rescheduleCount);
        $this->assertSame([], $found->pendingChanges);
    }

    /** A reservation of another barbershop, or one that does not exist, is not found. */
    public function test_only_the_reservations_of_the_current_barbershop_are_found(): void
    {
        $reservation = $this->reservation('2030-03-04 10:00');
        $this->repository->save($reservation);

        $other = new EloquentReservationRepository(new FixedBarbershopContext(new BarbershopId($this->fadeLab)));

        $this->assertNull($other->find($reservation->id));
        $this->assertNull($this->repository->find($this->repository->nextId()));
    }

    /** The creation is stored with its entry of the history: no previous status, the author and the moment (RE-09). */
    public function test_creation_stores_its_history_entry(): void
    {
        $reservation = $this->reservation('2030-03-04 10:00', Author::person(new UserId($this->anaUser)), '2030-03-01 08:15');
        $this->repository->save($reservation);

        $entry = DB::table('reservation_status_history')->sole();

        $this->assertSame($this->puraNavaja, $entry->barbershop_id);
        $this->assertSame($reservation->id->value, $entry->reservation_id);
        $this->assertNull($entry->from_status);
        $this->assertSame('temporary', $entry->to_status);
        $this->assertSame($this->anaUser, $entry->changed_by_user_id);
        $this->assertSame(self::at('2030-03-01 08:15')->getTimestamp(), (new DateTimeImmutable($entry->changed_at))->getTimestamp());
        $this->assertSame([], $reservation->pendingChanges);
    }

    /** A change of status updates the row and adds its entry, by the person or by the system, without repeating the earlier ones. */
    public function test_a_status_change_is_stored_with_its_entry_and_does_not_repeat_the_earlier_ones(): void
    {
        $reservation = $this->reservation('2030-03-04 10:00', Author::system(), '2030-03-01 08:00');
        $this->repository->save($reservation);

        $reservation->confirm(self::at('2030-03-01 08:05'), Author::system());
        $this->repository->save($reservation);
        $reservation->complete(self::at('2030-03-04 10:50'), Author::person(new UserId($this->anaUser)));
        $this->repository->save($reservation);
        $this->repository->save($reservation);

        $this->assertSame(ReservationStatus::Completed, $this->repository->find($reservation->id)?->status);

        $entries = DB::table('reservation_status_history')->orderBy('changed_at')->get();
        $this->assertSame(
            [[null, 'temporary', null], ['temporary', 'confirmed', null], ['confirmed', 'completed', $this->anaUser]],
            $entries->map(fn (object $entry): array => [$entry->from_status, $entry->to_status, $entry->changed_by_user_id])->all(),
        );
    }

    /** The history is read back in the order it happened, with its authors (the system has none), and only for a reservation of the current barbershop (RE-09). */
    public function test_the_history_is_read_back_in_order(): void
    {
        $reservation = $this->reservation('2030-03-04 10:00', Author::person(new UserId($this->anaUser)), '2030-03-01 08:00');
        $this->repository->save($reservation);
        $reservation->confirm(self::at('2030-03-01 08:05'), Author::system());
        $this->repository->save($reservation);

        $history = $this->repository->history($reservation->id);

        $this->assertSame(
            [[null, ReservationStatus::Temporary, $this->anaUser], [ReservationStatus::Temporary, ReservationStatus::Confirmed, null]],
            array_map(fn ($change): array => [$change->from, $change->to, $change->author->user?->value], $history),
        );
        $this->assertSame(self::at('2030-03-01 08:05')->getTimestamp(), $history[1]->at->getTimestamp());

        $other = new EloquentReservationRepository(new FixedBarbershopContext(new BarbershopId($this->fadeLab)));
        $this->assertSame([], $other->history($reservation->id));
    }

    /** A change and its entry are stored together: if the entry fails, the status does not change. */
    public function test_a_failed_entry_leaves_the_status_unchanged(): void
    {
        $reservation = $this->reservation('2030-03-04 10:00');
        $this->repository->save($reservation);
        $reservation->confirm(self::at('2030-03-01 08:05'), Author::person(new UserId((string) Str::uuid7())));

        try {
            $this->repository->save($reservation);
            $this->fail('An entry of an author that does not exist was stored.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }

        $this->assertSame(ReservationStatus::Temporary, $this->repository->find($reservation->id)?->status);
        $this->assertSame(1, DB::table('reservation_status_history')->count());
    }

    /** A reservation that overlaps another of the collaborator is rejected by the database and reported as a space not available (RE-01). */
    public function test_an_overlapping_reservation_is_rejected_by_the_database(): void
    {
        $this->repository->save($this->reservation('2030-03-04 10:00'));
        [$luis] = $this->insertCustomer($this->puraNavaja);

        try {
            $this->repository->save($this->reservation('2030-03-04 10:30', customer: $luis));
            $this->fail('A reservation over another was stored.');
        } catch (SlotUnavailableException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, DB::table('reservations')->count());
        $this->assertSame(1, DB::table('reservation_status_history')->count());
    }

    /** The same customer with two confirmed reservations at once is rejected by the database and reported as an overlap (RE-06). */
    public function test_a_customer_overlap_is_rejected_by_the_database_and_translated(): void
    {
        $author = Author::person(new UserId($this->anaUser));
        $first = $this->reservation('2030-03-04 10:00', $author);
        $first->confirm(self::at('2030-03-01 08:01'), $author);
        $this->repository->save($first);
        $second = $this->reservation('2030-03-04 10:30', $author, collaborator: $this->insertCollaborator($this->puraNavaja));
        $second->confirm(self::at('2030-03-01 08:01'), $author);

        $this->expectException(OverlappingReservationException::class);

        try {
            $this->repository->save($second);
        } finally {
            $this->assertSame(1, DB::table('reservations')->count());
            $this->assertSame(2, DB::table('reservation_status_history')->count());
        }
    }

    /** The overlap check sees only confirmed reservations of that customer that share a minute; touching ends do not count. */
    public function test_confirmed_overlap_of_a_customer_is_detected(): void
    {
        $author = Author::person(new UserId($this->anaUser));
        $confirmed = $this->reservation('2030-03-04 10:00', $author);
        $confirmed->confirm(self::at('2030-03-01 08:01'), $author);
        $this->repository->save($confirmed);
        $this->repository->save($this->reservation('2030-03-04 14:00', $author, collaborator: $this->insertCollaborator($this->puraNavaja)));
        $ana = new CustomerMembershipId($this->ana);
        $interval = fn (string $from, string $to): TimeInterval => TimeInterval::between(self::at($from), self::at($to));

        $this->assertTrue($this->repository->hasConfirmedOverlapping($ana, $interval('2030-03-04 10:30', '2030-03-04 11:15')));
        $this->assertFalse($this->repository->hasConfirmedOverlapping($ana, $interval('2030-03-04 10:45', '2030-03-04 11:30')));
        $this->assertFalse($this->repository->hasConfirmedOverlapping($ana, $interval('2030-03-04 14:00', '2030-03-04 14:45')), 'a temporary reservation does not count');

        [$luis] = $this->insertCustomer($this->puraNavaja);
        $this->assertFalse($this->repository->hasConfirmedOverlapping(new CustomerMembershipId($luis), $interval('2030-03-04 10:30', '2030-03-04 11:15')));
    }

    /** A reservation that ended in a final status releases the space for the next one. */
    public function test_a_cancelled_reservation_releases_its_space(): void
    {
        $first = $this->reservation('2030-03-04 10:00');
        $this->repository->save($first);
        $first->cancelByCustomer(self::at('2030-03-01 09:00'), Author::person(new UserId($this->anaUser)));
        $this->repository->save($first);

        [$luis] = $this->insertCustomer($this->puraNavaja);
        $this->repository->save($this->reservation('2030-03-04 10:00', customer: $luis));

        $this->assertSame(2, DB::table('reservations')->count());
    }

    /** A reservation of a service or collaborator of another barbershop is rejected by the composite keys. */
    public function test_a_reservation_cannot_point_at_another_barbershop(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $foreignCollaborator = $this->insertCollaborator($this->fadeLab);
        $this->scopeQueriesTo($this->puraNavaja);

        $reservation = Reservation::create(
            $this->repository->nextId(),
            new CollaboratorId($foreignCollaborator),
            new ServiceId($this->haircut),
            new CustomerMembershipId($this->ana),
            self::at('2030-03-04 10:00'),
            Money::of(8500),
            Duration::ofMinutes(45),
            4,
            Author::system(),
            self::at('2030-03-01 08:00'),
        );

        try {
            $this->repository->save($reservation);
            $this->fail('A reservation pointed at a collaborator of another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
    }

    /** A 45-minute reservation of Ana with Kevin at the given moment of 4 March. */
    private function reservation(string $startsAt, ?Author $author = null, string $at = '2030-03-01 08:00', ?string $customer = null, ?string $collaborator = null): Reservation
    {
        return Reservation::create(
            $this->repository->nextId(),
            new CollaboratorId($collaborator ?? $this->kevin),
            new ServiceId($this->haircut),
            new CustomerMembershipId($customer ?? $this->ana),
            self::at($startsAt),
            Money::of(8500),
            Duration::ofMinutes(45),
            4,
            $author ?? Author::system(),
            self::at($at),
        );
    }

    /** An instant in the time zone of Costa Rica. */
    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert an account and its collaborator profile in the barbershop the queries reach and return the identifier of the profile. */
    private function insertCollaborator(string $barbershopId): string
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => 'Kevin Solano', 'email' => "{$userId}@barberhub.test", 'password' => 'hash']);
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $userId]);

        return $id;
    }

    /**
     * Insert an account with a customer membership and return the membership and the account.
     *
     * @return array{string, string}
     */
    private function insertCustomer(string $barbershopId): array
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => 'Ana Rojas', 'email' => "{$userId}@barberhub.test", 'password' => 'hash']);
        DB::table('memberships')->insert(['id' => $id, 'user_id' => $userId, 'barbershop_id' => $barbershopId, 'role' => 'customer']);

        return [$id, $userId];
    }

    /** Insert a service in the barbershop the queries reach and return its identifier. */
    private function insertService(string $barbershopId): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => 'Degradado', 'duration_minutes' => 45, 'price' => 8500]);

        return $id;
    }
}
