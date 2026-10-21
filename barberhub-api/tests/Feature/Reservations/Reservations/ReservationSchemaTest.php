<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The integrity of the reservation tables lives in PostgreSQL too, and no relation crosses barbershops (ADR 0009, MT-04). */
final class ReservationSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string CHECK_VIOLATION = '23514';

    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string EXCLUSION_VIOLATION = '23P01';

    private string $puraNavaja;

    private string $fadeLab;

    private string $kevin;

    private string $daniela;

    private string $haircut;

    private string $ana;

    private string $luis;

    /** Two barbershops; the first has two collaborators, a service and two customers, and the queries of the test reach it. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->scopeQueriesTo($this->puraNavaja);
        $this->kevin = $this->insertCollaborator($this->puraNavaja, 'kevin@barberhub.test');
        $this->daniela = $this->insertCollaborator($this->puraNavaja, 'daniela@barberhub.test');
        $this->haircut = $this->insertService($this->puraNavaja);
        $this->ana = $this->insertCustomer($this->puraNavaja, 'ana@barberhub.test');
        $this->luis = $this->insertCustomer($this->puraNavaja, 'luis@barberhub.test');
    }

    /** The tables have the columns of the issue and none of the ones that arrive with their own issues. */
    public function test_tables_have_only_the_columns_of_this_issue(): void
    {
        $schema = Schema::connection('pgsql_admin');

        $this->assertEqualsCanonicalizing([
            'id', 'barbershop_id', 'collaborator_id', 'service_id', 'customer_membership_id', 'status', 'starts_at', 'ends_at',
            'blocked_until', 'price', 'duration_minutes', 'cancellation_deadline_hours', 'reschedule_count', 'created_at', 'updated_at',
        ], $schema->getColumnListing('reservations'));
        $this->assertEqualsCanonicalizing(
            ['id', 'barbershop_id', 'reservation_id', 'from_status', 'to_status', 'changed_by_user_id', 'changed_at'],
            $schema->getColumnListing('reservation_status_history'),
        );
    }

    /** A reservation gets a version 7 identifier, no reschedules and its timestamps from the database. */
    public function test_a_reservation_gets_its_defaults(): void
    {
        $this->reservation();

        $row = DB::table('reservations')->sole();

        $this->assertTrue(Str::isUuid($row->id, 7));
        $this->assertSame(0, $row->reschedule_count);
        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);
    }

    /** Only the seven statuses of RE-04 are stored, in the reservation and in both columns of the history. */
    public function test_status_must_be_one_of_the_seven(): void
    {
        foreach (['temporary', 'confirmed', 'completed', 'no_show', 'cancelled_by_customer', 'cancelled_by_barbershop', 'expired'] as $index => $status) {
            $this->reservation(['status' => $status, ...$this->slot('2030-03-0'.($index + 1).' 10:00')]);
        }

        $this->assertSame(7, DB::table('reservations')->count());
        $this->assertCheckViolation(fn () => $this->reservation(['status' => 'pending']));
        $this->assertCheckViolation(fn () => $this->reservation(['status' => 'cancelled']));
    }

    /** The service starts before it ends. */
    public function test_start_must_be_before_end(): void
    {
        $slot = $this->slot('2030-03-04 10:00');

        $this->assertCheckViolation(fn () => $this->reservation(['ends_at' => $slot['starts_at'], 'blocked_until' => $slot['starts_at']]));
        $this->assertCheckViolation(fn () => $this->reservation(['ends_at' => '2030-03-04 09:00:00-06', 'blocked_until' => '2030-03-04 09:05:00-06']));
    }

    /** The block lasts at least until the service ends; with no cleanup it is allowed to be equal. */
    public function test_block_must_not_end_before_the_service(): void
    {
        $slot = $this->slot('2030-03-04 10:00');

        $this->reservation(['blocked_until' => $slot['ends_at']]);
        $this->assertSame(1, DB::table('reservations')->count());
        $this->assertCheckViolation(fn () => $this->reservation([...$this->slot('2030-03-05 10:00'), 'blocked_until' => '2030-03-05 10:44:00-06']));
    }

    /** The price is at least one colon and the duration at least one minute (SV-01, SV-03). */
    public function test_price_and_duration_must_be_positive(): void
    {
        $this->assertCheckViolation(fn () => $this->reservation(['price' => 0]));
        $this->assertCheckViolation(fn () => $this->reservation(['price' => -1]));
        $this->assertCheckViolation(fn () => $this->reservation(['duration_minutes' => 0]));
        $this->assertCheckViolation(fn () => $this->reservation(['duration_minutes' => -15]));

        $this->reservation(['price' => 1, 'duration_minutes' => 1]);
        $this->assertSame(1, DB::table('reservations')->count());
    }

    /** The cancellation deadline is not negative; zero hours means that cancelling is free until the start (CA-01). */
    public function test_cancellation_deadline_must_not_be_negative(): void
    {
        $this->assertCheckViolation(fn () => $this->reservation(['cancellation_deadline_hours' => -1]));

        $this->reservation(['cancellation_deadline_hours' => 0]);
        $this->assertSame(1, DB::table('reservations')->count());
    }

    /** A reservation is rescheduled twice at most (CA-05). */
    public function test_reschedule_count_goes_from_zero_to_two(): void
    {
        $this->assertCheckViolation(fn () => $this->reservation(['reschedule_count' => 3]));
        $this->assertCheckViolation(fn () => $this->reservation(['reschedule_count' => -1]));

        $this->reservation(['reschedule_count' => 2]);
        $this->assertSame(1, DB::table('reservations')->count());
    }

    /** The collaborator, the service and the customer must belong to the barbershop of the reservation, even for the application role. */
    public function test_relations_must_belong_to_the_barbershop_of_the_reservation(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $foreignCollaborator = $this->insertCollaborator($this->fadeLab, 'otro@barberhub.test');
        $foreignService = $this->insertService($this->fadeLab);
        $foreignCustomer = $this->insertCustomer($this->fadeLab, 'otra@barberhub.test');
        $this->scopeQueriesTo($this->puraNavaja);

        foreach (['collaborator_id' => $foreignCollaborator, 'service_id' => $foreignService, 'customer_membership_id' => $foreignCustomer] as $column => $foreign) {
            try {
                DB::transaction(fn () => $this->reservation([$column => $foreign]));
                $this->fail("A reservation pointed at a {$column} of another barbershop.");
            } catch (QueryException $exception) {
                $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getCode());
            }
        }

        $this->assertSame(0, DB::table('reservations')->count());
    }

    /** A collaborator with a reservation cannot be deleted, so the reservation never points at nothing. */
    public function test_a_collaborator_with_reservations_cannot_be_deleted(): void
    {
        $this->reservation();

        try {
            DB::transaction(fn () => DB::table('collaborators')->where('id', $this->kevin)->delete());
            $this->fail('A collaborator with reservations was deleted.');
        } catch (QueryException $exception) {
            $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getCode());
        }
    }

    /** Two reservations of a collaborator that share a minute are rejected, whether they are temporary or confirmed (RE-01). */
    #[DataProvider('statusesThatHoldTheSpace')]
    public function test_overlapping_reservations_of_a_collaborator_are_rejected(string $held, string $incoming): void
    {
        $this->reservation(['status' => $held, ...$this->slot('2030-03-04 10:00')]);

        foreach (['2030-03-04 10:44', '2030-03-04 10:00', '2030-03-04 09:30', '2030-03-04 10:20'] as $start) {
            try {
                DB::transaction(fn () => $this->reservation(['status' => $incoming, 'customer_membership_id' => $this->luis, ...$this->slot($start)]));
                $this->fail("Accepted a {$incoming} reservation at {$start} over a {$held} one.");
            } catch (QueryException $exception) {
                $this->assertSame(self::EXCLUSION_VIOLATION, $exception->getCode());
            }
        }

        $this->assertSame(1, DB::table('reservations')->count());
    }

    /** The five minutes of cleaning belong to the block: a reservation that starts in them overlaps (DI-01). */
    public function test_the_cleanup_is_part_of_the_block(): void
    {
        $this->reservation($this->slot('2030-03-04 10:00'));

        $this->assertExclusionViolation(fn () => $this->reservation(['customer_membership_id' => $this->luis, ...$this->slot('2030-03-04 10:49', 30)]));
    }

    /** A reservation that starts exactly when the previous block ends is allowed, and so is one that ends exactly when the next starts. */
    public function test_contiguous_reservations_of_a_collaborator_are_allowed(): void
    {
        $this->reservation($this->slot('2030-03-04 10:00'));

        $this->reservation(['customer_membership_id' => $this->luis, ...$this->slot('2030-03-04 10:50')]);
        $this->reservation(['customer_membership_id' => $this->luis, ...$this->slot('2030-03-04 09:10', 45)]);

        $this->assertSame(3, DB::table('reservations')->count());
    }

    /** The exclusion is per collaborator: another one, or the same hour another day, is free. */
    public function test_other_collaborators_and_other_days_are_free(): void
    {
        $this->reservation($this->slot('2030-03-04 10:00'));

        $this->reservation(['collaborator_id' => $this->daniela, 'customer_membership_id' => $this->luis, ...$this->slot('2030-03-04 10:00')]);
        $this->reservation(['customer_membership_id' => $this->luis, ...$this->slot('2030-03-05 10:00')]);

        $this->assertSame(3, DB::table('reservations')->count());
    }

    /** A reservation that ended in any final status frees the space, whether it is stored that way or changes to it later. */
    #[DataProvider('statusesThatFreeTheSpace')]
    public function test_a_final_status_frees_the_space_of_the_collaborator(string $released): void
    {
        $this->reservation(['status' => $released, ...$this->slot('2030-03-04 10:00')]);
        $this->reservation(['customer_membership_id' => $this->luis, ...$this->slot('2030-03-04 10:00')]);
        $this->assertSame(2, DB::table('reservations')->count());

        $taken = $this->reservation(['status' => 'confirmed', 'collaborator_id' => $this->daniela, ...$this->slot('2030-03-04 10:00')]);
        $this->assertExclusionViolation(fn () => $this->reservation(['collaborator_id' => $this->daniela, 'customer_membership_id' => $this->luis, ...$this->slot('2030-03-04 10:00')]));

        DB::table('reservations')->where('id', $taken)->update(['status' => $released]);

        $this->reservation(['collaborator_id' => $this->daniela, 'customer_membership_id' => $this->luis, ...$this->slot('2030-03-04 10:00')]);
        $this->assertSame(4, DB::table('reservations')->count());
    }

    /** A customer has no two confirmed reservations that share a minute, even with different collaborators (RE-06). */
    public function test_overlapping_confirmed_reservations_of_a_customer_are_rejected(): void
    {
        $this->reservation(['status' => 'confirmed', ...$this->slot('2030-03-04 10:00')]);

        foreach (['2030-03-04 10:44', '2030-03-04 10:00', '2030-03-04 09:30'] as $start) {
            $this->assertExclusionViolation(fn () => $this->reservation(['status' => 'confirmed', 'collaborator_id' => $this->daniela, ...$this->slot($start)]));
        }

        $this->assertSame(1, DB::table('reservations')->count());
    }

    /** Only the service counts for the customer, not the cleaning, and touching reservations are allowed. */
    public function test_contiguous_confirmed_reservations_of_a_customer_are_allowed(): void
    {
        $this->reservation(['status' => 'confirmed', ...$this->slot('2030-03-04 10:00')]);

        $this->reservation(['status' => 'confirmed', 'collaborator_id' => $this->daniela, ...$this->slot('2030-03-04 10:45')]);
        $this->reservation(['status' => 'confirmed', 'collaborator_id' => $this->daniela, ...$this->slot('2030-03-04 09:15')]);

        $this->assertSame(3, DB::table('reservations')->count());
    }

    /** A temporary reservation does not block the customer, and another customer is free at the same time. */
    public function test_temporary_reservations_and_other_customers_do_not_collide(): void
    {
        $this->reservation(['status' => 'temporary', ...$this->slot('2030-03-04 10:00')]);
        $this->reservation(['status' => 'temporary', 'collaborator_id' => $this->daniela, ...$this->slot('2030-03-04 10:00')]);
        $this->reservation(['status' => 'confirmed', 'collaborator_id' => $this->daniela, 'customer_membership_id' => $this->luis, ...$this->slot('2030-03-04 11:00')]);
        $this->reservation(['status' => 'confirmed', 'customer_membership_id' => $this->ana, ...$this->slot('2030-03-04 12:00')]);

        $this->assertSame(4, DB::table('reservations')->count());
    }

    /** A reservation that ended in any final status frees the customer too. */
    #[DataProvider('statusesThatFreeTheSpace')]
    public function test_a_final_status_frees_the_customer(string $released): void
    {
        $first = $this->reservation(['status' => 'confirmed', ...$this->slot('2030-03-04 10:00')]);
        DB::table('reservations')->where('id', $first)->update(['status' => $released]);

        $this->reservation(['status' => 'confirmed', 'collaborator_id' => $this->daniela, ...$this->slot('2030-03-04 10:00')]);

        $this->assertSame(2, DB::table('reservations')->count());
    }

    /** The two barbershops do not share spaces: the same collaborator hour and customer in another barbershop never collide. */
    public function test_exclusions_do_not_cross_barbershops(): void
    {
        $this->reservation(['status' => 'confirmed', ...$this->slot('2030-03-04 10:00')]);
        $this->scopeQueriesTo($this->fadeLab);
        $collaborator = $this->insertCollaborator($this->fadeLab, 'otro@barberhub.test');
        $service = $this->insertService($this->fadeLab);
        $customer = $this->insertCustomer($this->fadeLab, 'otra@barberhub.test');

        $this->reservation([
            'barbershop_id' => $this->fadeLab,
            'collaborator_id' => $collaborator,
            'service_id' => $service,
            'customer_membership_id' => $customer,
            'status' => 'confirmed',
            ...$this->slot('2030-03-04 10:00'),
        ]);

        $this->assertSame(1, DB::table('reservations')->count());
    }

    /** An entry of the history has no previous status when the reservation came to be and no author when the system changed it. */
    public function test_history_entries_accept_no_previous_status_and_no_author(): void
    {
        $id = $this->reservation();
        $userId = $this->userOf($this->ana);

        $this->history($id, ['from_status' => null, 'to_status' => 'temporary', 'changed_by_user_id' => $userId]);
        $this->history($id, ['from_status' => 'temporary', 'to_status' => 'confirmed', 'changed_by_user_id' => null]);

        $this->assertSame(2, DB::table('reservation_status_history')->count());

        $created = DB::table('reservation_status_history')->whereNull('from_status')->sole();
        $changed = DB::table('reservation_status_history')->whereNotNull('from_status')->sole();
        $this->assertTrue(Str::isUuid($created->id, 7));
        $this->assertSame($userId, $created->changed_by_user_id);
        $this->assertNull($changed->changed_by_user_id);
    }

    /** Both statuses of an entry are among the seven. */
    public function test_history_statuses_must_be_valid(): void
    {
        $id = $this->reservation();

        $this->assertCheckViolation(fn () => $this->history($id, ['from_status' => 'pending', 'to_status' => 'confirmed']));
        $this->assertCheckViolation(fn () => $this->history($id, ['from_status' => null, 'to_status' => 'cancelled']));
    }

    /** An entry points at a reservation of its own barbershop and at an existing account. */
    public function test_history_must_point_at_a_reservation_of_the_same_barbershop(): void
    {
        $id = $this->reservation();
        $this->scopeQueriesTo($this->fadeLab);

        try {
            DB::transaction(fn () => $this->history($id, ['barbershop_id' => $this->fadeLab]));
            $this->fail('An entry pointed at a reservation of another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getCode());
        }

        $this->scopeQueriesTo($this->puraNavaja);

        try {
            DB::transaction(fn () => $this->history($id, ['changed_by_user_id' => (string) Str::uuid7()]));
            $this->fail('An entry pointed at an account that does not exist.');
        } catch (QueryException $exception) {
            $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getCode());
        }
    }

    /** The history of a reservation goes away with it. */
    public function test_history_is_removed_with_its_reservation(): void
    {
        $id = $this->reservation();
        $this->history($id);

        DB::table('reservations')->where('id', $id)->delete();

        $this->assertSame(0, DB::table('reservation_status_history')->count());
    }

    /** With the context of a barbershop only its rows are seen, and without a context none is. */
    public function test_policy_hides_the_rows_of_other_barbershops(): void
    {
        $id = $this->reservation();
        $this->history($id);

        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame(0, DB::table('reservations')->count());
        $this->assertSame(0, DB::table('reservation_status_history')->count());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('reservations')->count());
        $this->assertSame(0, DB::table('reservation_status_history')->count());

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(1, DB::table('reservations')->count());
        $this->assertSame(1, DB::table('reservation_status_history')->count());
    }

    /** Writing a row of another barbershop is refused by the policy with the SQLSTATE of a privilege violation. */
    public function test_policy_rejects_writing_for_another_barbershop(): void
    {
        $id = $this->reservation();

        foreach ([
            fn () => $this->reservation(['barbershop_id' => $this->fadeLab, ...$this->slot('2030-03-06 10:00')]),
            fn () => $this->history($id, ['barbershop_id' => $this->fadeLab]),
            fn () => DB::table('reservations')->where('id', $id)->update(['barbershop_id' => $this->fadeLab]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('A row was written for another barbershop.');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->getCode());
            }
        }

        $this->assertSame($this->puraNavaja, DB::table('reservations')->where('id', $id)->value('barbershop_id'));
    }

    /** Each table has the policy of the convention alone, enabled and forced, so it binds the owner of the table too. */
    #[DataProvider('tables')]
    public function test_table_has_its_isolation_policy_enabled_and_forced(string $table): void
    {
        $security = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where oid = ?::regclass', [$table]);
        $policies = DB::select("select policyname from pg_policies where schemaname = 'public' and tablename = ?", [$table]);

        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertSame(["{$table}_barbershop_isolation"], array_column($policies, 'policyname'));
    }

    /**
     * The statuses that keep the space taken, as the one already stored and the one that arrives.
     *
     * @return array<string, array{string, string}>
     */
    public static function statusesThatHoldTheSpace(): array
    {
        return [
            'temporary over temporary' => ['temporary', 'temporary'],
            'temporary over confirmed' => ['temporary', 'confirmed'],
            'confirmed over temporary' => ['confirmed', 'temporary'],
            'confirmed over confirmed' => ['confirmed', 'confirmed'],
        ];
    }

    /**
     * The statuses that release the space.
     *
     * @return array<string, array{string}>
     */
    public static function statusesThatFreeTheSpace(): array
    {
        return [
            'completed' => ['completed'],
            'no show' => ['no_show'],
            'cancelled by the customer' => ['cancelled_by_customer'],
            'cancelled by the barbershop' => ['cancelled_by_barbershop'],
            'expired' => ['expired'],
        ];
    }

    /**
     * The tables of the module.
     *
     * @return array<string, array{string}>
     */
    public static function tables(): array
    {
        return ['reservations' => ['reservations'], 'history' => ['reservation_status_history']];
    }

    /**
     * The three instants of a reservation that starts at a moment of Costa Rica: its start, its end and the end of the cleaning.
     *
     * @return array{starts_at: string, ends_at: string, blocked_until: string}
     */
    private function slot(string $start, int $minutes = 45): array
    {
        $startsAt = new DateTimeImmutable("{$start}:00-06:00");
        $format = 'Y-m-d H:i:sP';

        return [
            'starts_at' => $startsAt->format($format),
            'ends_at' => $startsAt->modify("+{$minutes} minutes")->format($format),
            'blocked_until' => $startsAt->modify('+'.($minutes + 5).' minutes')->format($format),
        ];
    }

    /**
     * Insert a reservation of Ana with Kevin on 4 March at 10:00 unless the overrides say otherwise, and return its identifier.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function reservation(array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('reservations')->insert([
            'id' => $id,
            'barbershop_id' => $this->puraNavaja,
            'collaborator_id' => $this->kevin,
            'service_id' => $this->haircut,
            'customer_membership_id' => $this->ana,
            'status' => 'temporary',
            ...$this->slot('2030-03-04 10:00'),
            'price' => 8500,
            'duration_minutes' => 45,
            'cancellation_deadline_hours' => 4,
            ...$overrides,
        ]);

        return $id;
    }

    /**
     * Insert an entry of the history of a reservation.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function history(string $reservationId, array $overrides = []): void
    {
        DB::table('reservation_status_history')->insert([
            'barbershop_id' => $this->puraNavaja,
            'reservation_id' => $reservationId,
            'from_status' => null,
            'to_status' => 'temporary',
            'changed_by_user_id' => null,
            'changed_at' => '2030-03-01 08:00:00-06',
            ...$overrides,
        ]);
    }

    /**
     * Run an insert that must break a CHECK constraint inside a savepoint.
     *
     * @param  Closure(): mixed  $insert
     */
    private function assertCheckViolation(Closure $insert): void
    {
        try {
            DB::transaction($insert);
            $this->fail('A row that breaks a CHECK was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame(self::CHECK_VIOLATION, $exception->getCode());
        }
    }

    /**
     * Run an insert that must break an exclusion constraint inside a savepoint.
     *
     * @param  Closure(): mixed  $insert
     */
    private function assertExclusionViolation(Closure $insert): void
    {
        try {
            DB::transaction($insert);
            $this->fail('A row that overlaps another was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame(self::EXCLUSION_VIOLATION, $exception->getCode());
        }
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert an account and its collaborator profile and return the identifier of the profile. */
    private function insertCollaborator(string $barbershopId, string $email): string
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => $email, 'email' => $email, 'password' => 'hash']);
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $userId]);

        return $id;
    }

    /** Insert an account with a customer membership and return the identifier of the membership. */
    private function insertCustomer(string $barbershopId, string $email): string
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => $email, 'email' => $email, 'password' => 'hash']);
        DB::table('memberships')->insert(['id' => $id, 'user_id' => $userId, 'barbershop_id' => $barbershopId, 'role' => 'customer']);

        return $id;
    }

    /** The account behind a membership. */
    private function userOf(string $membershipId): string
    {
        return (string) DB::table('memberships')->where('id', $membershipId)->value('user_id');
    }

    /** Insert a service and return its identifier. */
    private function insertService(string $barbershopId): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => 'Corte', 'duration_minutes' => 45, 'price' => 8500]);

        return $id;
    }
}
