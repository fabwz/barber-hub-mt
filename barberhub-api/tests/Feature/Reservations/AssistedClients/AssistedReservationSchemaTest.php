<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\AssistedClients;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * The integrity of the assisted reservations lives in PostgreSQL too (RF-12, CL-06, MT-04): a reservation belongs to a customer role or to an
 * assisted client and never to both or to none, no relation crosses barbershops and an assisted client of one barbershop does not exist in another.
 */
final class AssistedReservationSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private const string CHECK_VIOLATION = '23514';

    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string POLICY_VIOLATION = '42501';

    private string $assisted;

    private string $ana;

    private string $ownerRole;

    /** Seed the barbershops, a customer, an assisted client and the role of the owner. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        [, $this->ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->assisted = $this->insertAssistedClient($this->puraNavaja, 'Rodrigo Chaves');
        $this->ownerRole = (string) DB::table('memberships')->where('user_id', $this->owner)->value('id');
    }

    private function insertAssistedClient(string $barbershopId, string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('assisted_clients')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'phone' => '8812 3456']);

        return $id;
    }

    /**
     * Insert a reservation with the customer columns given.
     *
     * @param  array<string, mixed>  $customer
     */
    private function reservationOf(array $customer, string $startsAt = '2030-03-05 10:00:00-06'): string
    {
        $id = (string) Str::uuid7();
        DB::table('reservations')->insert([
            'id' => $id,
            'barbershop_id' => $this->puraNavaja,
            'collaborator_id' => $this->daniela,
            'service_id' => $this->degradado,
            'status' => 'confirmed',
            'starts_at' => $startsAt,
            'ends_at' => new DateTimeImmutable($startsAt)->modify('+45 minutes')->format('Y-m-d H:i:sP'),
            'blocked_until' => new DateTimeImmutable($startsAt)->modify('+50 minutes')->format('Y-m-d H:i:sP'),
            'price' => 7000,
            'duration_minutes' => 45,
            'cancellation_deadline_hours' => 24,
            ...$customer,
        ]);

        return $id;
    }

    /** The assisted client gets a version 7 identifier and its timestamps; the email is optional. */
    public function test_an_assisted_client_gets_its_defaults_and_the_email_is_optional(): void
    {
        $row = DB::table('assisted_clients')->where('id', $this->assisted)->sole();

        $this->assertTrue(Str::isUuid($row->id, 7));
        $this->assertNull($row->email);
        $this->assertNotNull($row->created_at);
    }

    /** The name and the phone cannot be empty (CL-06). */
    public function test_the_name_and_the_phone_cannot_be_empty(): void
    {
        foreach ([['name' => '  ', 'phone' => '8812 3456'], ['name' => 'Rosa', 'phone' => ''], ['name' => 'Rosa', 'phone' => '8812 3456', 'email' => '']] as $row) {
            try {
                DB::transaction(fn () => DB::table('assisted_clients')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, ...$row]));
                $this->fail('An empty value was stored.');
            } catch (QueryException $exception) {
                $this->assertSame(self::CHECK_VIOLATION, $exception->getCode());
            }
        }
    }

    /** A reservation of an account and one of an assisted client are both valid (RF-12). */
    public function test_a_reservation_belongs_to_an_account_or_to_an_assisted_client(): void
    {
        $this->reservationOf(['customer_membership_id' => $this->ana], '2030-03-05 10:00:00-06');
        $this->reservationOf(['assisted_client_id' => $this->assisted, 'created_by_membership_id' => $this->ownerRole], '2030-03-05 14:00:00-06');

        $this->assertSame(2, DB::table('reservations')->count());
    }

    /** The database refuses a reservation of both or of none: the restriction is not only in the code. */
    public function test_a_reservation_of_both_or_of_none_is_refused(): void
    {
        foreach ([[], ['customer_membership_id' => $this->ana, 'assisted_client_id' => $this->assisted]] as $customer) {
            try {
                DB::transaction(fn () => $this->reservationOf($customer));
                $this->fail('A reservation without exactly one customer was stored.');
            } catch (QueryException $exception) {
                $this->assertSame(self::CHECK_VIOLATION, $exception->getCode());
                $this->assertStringContainsString('reservations_customer_check', $exception->getMessage());
            }
        }

        $this->assertSame(0, DB::table('reservations')->count());
    }

    /** An assisted client of another barbershop cannot be the customer of a reservation: the foreign key carries the barbershop (MT-04). */
    public function test_a_reservation_cannot_point_at_an_assisted_client_of_another_barbershop(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $foreign = $this->insertAssistedClient($this->fadeLab, 'Bea Solano');
        $this->scopeQueriesTo($this->puraNavaja);

        try {
            DB::transaction(fn () => $this->reservationOf(['assisted_client_id' => $foreign]));
            $this->fail('A reservation pointed at an assisted client of another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getCode());
        }
    }

    /** The owner who registered it must be a role of the same barbershop (MT-04). */
    public function test_the_creator_must_be_a_role_of_the_same_barbershop(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $foreignRole = (string) Str::uuid7();
        $foreignOwner = $this->insertUser('Dueño de Fade Lab');
        DB::table('memberships')->insert(['id' => $foreignRole, 'user_id' => $foreignOwner, 'barbershop_id' => $this->fadeLab, 'role' => 'owner']);
        $this->scopeQueriesTo($this->puraNavaja);

        try {
            DB::transaction(fn () => $this->reservationOf(['assisted_client_id' => $this->assisted, 'created_by_membership_id' => $foreignRole]));
            $this->fail('A reservation was registered by a role of another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getCode());
        }
    }

    /** A barbershop only sees its own assisted clients, and without a barbershop none is visible; writing for another is refused (MT-06, CL-06). */
    public function test_a_barbershop_only_sees_its_own_assisted_clients(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $other = $this->insertAssistedClient($this->fadeLab, 'Bea Solano');

        $this->assertSame([$other], DB::table('assisted_clients')->pluck('id')->all());

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$this->assisted], DB::table('assisted_clients')->pluck('id')->all());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('assisted_clients')->count());

        $this->scopeQueriesTo($this->puraNavaja);

        try {
            DB::transaction(fn () => $this->insertAssistedClient($this->fadeLab, 'Intruso'));
            $this->fail('An assisted client of another barbershop was inserted.');
        } catch (QueryException $exception) {
            $this->assertSame(self::POLICY_VIOLATION, $exception->getCode());
        }
    }

    /** An assisted reservation holds the space of the collaborator like any other: the exclusion of the database is the second barrier (RE-01). */
    public function test_the_exclusion_of_the_collaborator_also_covers_assisted_reservations(): void
    {
        $this->reservationOf(['assisted_client_id' => $this->assisted, 'created_by_membership_id' => $this->ownerRole]);

        try {
            DB::transaction(fn () => $this->reservationOf(['customer_membership_id' => $this->ana], '2030-03-05 10:30:00-06'));
            $this->fail('Two reservations overlapped the same collaborator.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->getCode());
            $this->assertStringContainsString('reservations_no_overlap_excl', $exception->getMessage());
        }
    }
}
