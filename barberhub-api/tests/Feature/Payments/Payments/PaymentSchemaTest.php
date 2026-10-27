<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The integrity of the payments lives in PostgreSQL too: one active payment per reservation, one identifier per provider and no relation across barbershops (PA-02, PA-06, MT-04). */
final class PaymentSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string CHECK_VIOLATION = '23514';

    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string UNIQUE_VIOLATION = '23505';

    private string $puraNavaja;

    private string $fadeLab;

    private string $reservation;

    /** Two barbershops; the first has a reservation to pay and the queries of the test reach it. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->scopeQueriesTo($this->puraNavaja);
        $this->reservation = $this->insertReservation($this->puraNavaja, 'kevin@barberhub.test', 'ana@barberhub.test', '2030-03-04 10:00');
    }

    /** The table has the columns of the issue and none of the ones of the webhooks, which this stage does not use. */
    public function test_the_table_has_only_the_columns_of_this_issue(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'barbershop_id', 'reservation_id', 'provider', 'method', 'status', 'amount', 'provider_currency', 'provider_amount',
            'provider_payment_id', 'failure_reason', 'created_at', 'updated_at',
        ], Schema::connection('pgsql_admin')->getColumnListing('payments'));
        $this->assertFalse(Schema::connection('pgsql_admin')->hasTable('gateway_events'));
    }

    /** A payment gets a version 7 identifier and its timestamps from the database. */
    public function test_a_payment_gets_its_defaults(): void
    {
        $this->payment();

        $row = DB::table('payments')->sole();

        $this->assertTrue(Str::isUuid($row->id, 7));
        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);
        $this->assertNull($row->provider_payment_id);
    }

    /** Only the two providers, the two methods and the five statuses are stored. */
    public function test_provider_method_and_status_are_restricted(): void
    {
        foreach (['pending', 'approved', 'rejected', 'failed', 'refunded'] as $index => $status) {
            $this->payment([
                'reservation_id' => $this->insertReservation($this->puraNavaja, "k{$index}@barberhub.test", "a{$index}@barberhub.test", '2030-04-0'.($index + 1).' 10:00'),
                'status' => $status,
                'provider_payment_id' => "id_{$status}",
                ...($status === 'rejected' || $status === 'failed' ? ['failure_reason' => 'x'] : []),
            ]);
        }
        $this->payment(['provider' => 'paypal', 'method' => 'card', 'status' => 'rejected', 'reservation_id' => $this->insertReservation($this->puraNavaja, 'k9@barberhub.test', 'a9@barberhub.test', '2030-05-04 10:00')]);

        $this->assertSame(6, DB::table('payments')->count());
        $this->assertCheckViolation(fn () => $this->payment(['provider' => 'stripe']));
        $this->assertCheckViolation(fn () => $this->payment(['method' => 'cash']));
        $this->assertCheckViolation(fn () => $this->payment(['status' => 'paid']));
        $this->assertCheckViolation(fn () => $this->payment(['status' => 'cancelled']));
    }

    /** The amount is whole colones and at least one; the provider amount is positive too. */
    public function test_amounts_must_be_positive(): void
    {
        $this->assertCheckViolation(fn () => $this->payment(['amount' => 0]));
        $this->assertCheckViolation(fn () => $this->payment(['amount' => -1]));
        $this->assertCheckViolation(fn () => $this->payment(['provider_currency' => 'USD', 'provider_amount' => 0]));
        $this->assertCheckViolation(fn () => $this->payment(['provider_currency' => 'USD', 'provider_amount' => -5]));

        $this->payment(['amount' => 1, 'provider_currency' => 'USD', 'provider_amount' => 1]);
        $this->assertSame(1, DB::table('payments')->count());
    }

    /** The currency and the amount of the provider come together or not at all. */
    public function test_provider_currency_and_amount_come_together(): void
    {
        $this->assertCheckViolation(fn () => $this->payment(['provider_currency' => 'USD']));
        $this->assertCheckViolation(fn () => $this->payment(['provider_amount' => 510]));

        $this->payment(['provider_currency' => 'USD', 'provider_amount' => 510]);
        $this->assertSame(1, DB::table('payments')->count());
    }

    /** A payment that was approved or refunded has the identifier the provider gave to the charge. */
    #[DataProvider('statusesThatNeedACharge')]
    public function test_approved_and_refunded_payments_need_the_provider_identifier(string $status): void
    {
        $this->assertCheckViolation(fn () => $this->payment(['status' => $status]));

        $this->payment(['status' => $status, 'provider_payment_id' => 'onvo_1']);
        $this->assertSame(1, DB::table('payments')->count());
    }

    /** The reason of a failure exists only in a rejected or failed payment. */
    public function test_the_failure_reason_belongs_to_rejected_and_failed_payments(): void
    {
        $this->assertCheckViolation(fn () => $this->payment(['status' => 'pending', 'failure_reason' => 'Card declined']));

        $this->payment(['status' => 'rejected', 'failure_reason' => 'Card declined']);
        $this->assertSame(1, DB::table('payments')->count());
    }

    /** The identifier of the provider is unique per provider; payments not yet charged all have none. */
    public function test_the_provider_identifier_is_unique_per_provider(): void
    {
        $second = $this->insertReservation($this->puraNavaja, 'k2@barberhub.test', 'a2@barberhub.test', '2030-03-05 10:00');
        $third = $this->insertReservation($this->puraNavaja, 'k3@barberhub.test', 'a3@barberhub.test', '2030-03-06 10:00');
        $this->payment(['provider_payment_id' => 'abc']);

        $this->assertUniqueViolation(fn () => $this->payment(['reservation_id' => $second, 'provider_payment_id' => 'abc']));

        $this->payment(['reservation_id' => $second, 'provider' => 'paypal', 'method' => 'card', 'provider_payment_id' => 'abc']);
        $this->payment(['reservation_id' => $third]);
        $this->assertSame(3, DB::table('payments')->count());
    }

    /** The identifier of the provider is unique across barbershops too: the provider does not know them. */
    public function test_the_provider_identifier_is_unique_across_barbershops(): void
    {
        $this->payment(['provider_payment_id' => 'abc']);
        $this->scopeQueriesTo($this->fadeLab);
        $reservation = $this->insertReservation($this->fadeLab, 'otro@barberhub.test', 'otra@barberhub.test', '2030-03-04 10:00');

        $this->assertUniqueViolation(fn () => $this->payment(['barbershop_id' => $this->fadeLab, 'reservation_id' => $reservation, 'provider_payment_id' => 'abc']));
    }

    /** A reservation has one payment pending or approved at a time: the idempotency of the pay button. */
    #[DataProvider('activePairs')]
    public function test_a_reservation_has_one_active_payment(string $held, string $incoming): void
    {
        $this->payment(['status' => $held, 'provider_payment_id' => 'first']);

        $this->assertUniqueViolation(fn () => $this->payment(['status' => $incoming, 'provider_payment_id' => 'second']));

        $this->assertSame(1, DB::table('payments')->count());
    }

    /** A payment that ended without money, or whose money went back, frees the reservation for a new one. */
    #[DataProvider('statusesThatFreeTheReservation')]
    public function test_a_final_payment_lets_the_reservation_be_paid_again(string $released): void
    {
        $this->payment(['status' => $released, 'provider_payment_id' => 'first', ...($released === 'refunded' ? [] : ['failure_reason' => 'x'])]);
        $this->payment(['status' => $released, 'provider_payment_id' => 'second', ...($released === 'refunded' ? [] : ['failure_reason' => 'x'])]);
        $this->payment(['status' => 'pending']);

        $this->assertSame(3, DB::table('payments')->count());
        $this->assertUniqueViolation(fn () => $this->payment(['status' => 'pending']));
    }

    /** Bringing a payment back to an active status while another is active is rejected as well. */
    public function test_updating_to_an_active_status_is_also_guarded(): void
    {
        $this->payment(['status' => 'rejected', 'failure_reason' => 'x']);
        $this->payment(['status' => 'pending']);

        $this->assertUniqueViolation(fn () => DB::table('payments')->where('status', 'rejected')->update(['status' => 'pending', 'failure_reason' => null]));
    }

    /** Another reservation is free to be paid at the same time. */
    public function test_other_reservations_are_independent(): void
    {
        $this->payment();
        $this->payment(['reservation_id' => $this->insertReservation($this->puraNavaja, 'k2@barberhub.test', 'a2@barberhub.test', '2030-03-05 10:00')]);

        $this->assertSame(2, DB::table('payments')->count());
    }

    /** The reservation must belong to the barbershop of the payment, even for the application role. */
    public function test_the_reservation_must_belong_to_the_barbershop_of_the_payment(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $foreign = $this->insertReservation($this->fadeLab, 'otro@barberhub.test', 'otra@barberhub.test', '2030-03-04 10:00');
        $this->scopeQueriesTo($this->puraNavaja);

        try {
            DB::transaction(fn () => $this->payment(['reservation_id' => $foreign]));
            $this->fail('A payment pointed at a reservation of another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getCode());
        }

        $this->assertSame(0, DB::table('payments')->count());
    }

    /** A reservation with payments cannot be deleted, so the money trail never points at nothing. */
    public function test_a_reservation_with_payments_cannot_be_deleted(): void
    {
        $this->payment();

        try {
            DB::transaction(fn () => DB::table('reservations')->where('id', $this->reservation)->delete());
            $this->fail('A reservation with payments was deleted.');
        } catch (QueryException $exception) {
            $this->assertSame(self::FOREIGN_KEY_VIOLATION, $exception->getCode());
        }
    }

    /** The table has the policy of the convention alone, enabled and forced, so it binds the owner of the table too. */
    public function test_the_table_has_its_isolation_policy_enabled_and_forced(): void
    {
        $security = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where oid = 'payments'::regclass");
        $policies = DB::select("select policyname from pg_policies where schemaname = 'public' and tablename = 'payments'");

        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertSame(['payments_barbershop_isolation'], array_column($policies, 'policyname'));
    }

    /** With the context of a barbershop only its rows are seen, and without a context none is. */
    public function test_policy_hides_the_rows_of_other_barbershops(): void
    {
        $this->payment();

        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame(0, DB::table('payments')->count());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('payments')->count());

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(1, DB::table('payments')->count());
    }

    /** Writing a row of another barbershop is refused by the policy with the SQLSTATE of a privilege violation. */
    public function test_policy_rejects_writing_for_another_barbershop(): void
    {
        $id = $this->payment();

        foreach ([
            fn () => $this->payment(['barbershop_id' => $this->fadeLab, 'status' => 'rejected', 'failure_reason' => 'x']),
            fn () => DB::table('payments')->where('id', $id)->update(['barbershop_id' => $this->fadeLab]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('A row was written for another barbershop.');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->getCode());
            }
        }

        $this->assertSame($this->puraNavaja, DB::table('payments')->where('id', $id)->value('barbershop_id'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusesThatNeedACharge(): array
    {
        return ['approved' => ['approved'], 'refunded' => ['refunded']];
    }

    /**
     * The status of the payment already stored and of the one that arrives, both active.
     *
     * @return array<string, array{string, string}>
     */
    public static function activePairs(): array
    {
        return [
            'pending over pending' => ['pending', 'pending'],
            'pending over approved' => ['pending', 'approved'],
            'approved over pending' => ['approved', 'pending'],
            'approved over approved' => ['approved', 'approved'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusesThatFreeTheReservation(): array
    {
        return ['rejected' => ['rejected'], 'failed' => ['failed'], 'refunded' => ['refunded']];
    }

    /**
     * Insert a pending card payment of Pura Navaja for the reservation of the test, unless the overrides say otherwise, and return its identifier.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function payment(array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('payments')->insert([
            'id' => $id,
            'barbershop_id' => $this->puraNavaja,
            'reservation_id' => $this->reservation,
            'provider' => 'onvo',
            'method' => 'card',
            'status' => 'pending',
            'amount' => 2550,
            ...$overrides,
        ]);

        return $id;
    }

    /**
     * Run an insert that must break a CHECK constraint inside a savepoint.
     *
     * @param  Closure(): mixed  $insert
     */
    private function assertCheckViolation(Closure $insert): void
    {
        $this->assertViolation(self::CHECK_VIOLATION, $insert);
    }

    /**
     * Run a write that must break a unique constraint or index inside a savepoint.
     *
     * @param  Closure(): mixed  $write
     */
    private function assertUniqueViolation(Closure $write): void
    {
        $this->assertViolation(self::UNIQUE_VIOLATION, $write);
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function assertViolation(string $sqlState, Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail("A write that must fail with {$sqlState} was accepted.");
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->getCode());
        }
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert a reservation of a customer with a collaborator, with the service they need, and return its identifier. */
    private function insertReservation(string $barbershopId, string $collaboratorEmail, string $customerEmail, string $startsAt): string
    {
        $this->scopeQueriesTo($barbershopId);
        $collaboratorUser = $this->insertUser($collaboratorEmail);
        $customerUser = $this->insertUser($customerEmail);
        $collaboratorId = (string) Str::uuid7();
        $membershipId = (string) Str::uuid7();
        $serviceId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('collaborators')->insert(['id' => $collaboratorId, 'barbershop_id' => $barbershopId, 'user_id' => $collaboratorUser]);
        DB::table('memberships')->insert(['id' => $membershipId, 'user_id' => $customerUser, 'barbershop_id' => $barbershopId, 'role' => 'customer']);
        DB::table('services')->insert(['id' => $serviceId, 'barbershop_id' => $barbershopId, 'name' => 'Degradado', 'duration_minutes' => 45, 'price' => 8500]);
        DB::table('reservations')->insert([
            'id' => $id,
            'barbershop_id' => $barbershopId,
            'collaborator_id' => $collaboratorId,
            'service_id' => $serviceId,
            'customer_membership_id' => $membershipId,
            'status' => 'temporary',
            'starts_at' => "{$startsAt}:00-06",
            'ends_at' => date('Y-m-d H:i:s', (int) strtotime("{$startsAt} +45 minutes")).'-06',
            'blocked_until' => date('Y-m-d H:i:s', (int) strtotime("{$startsAt} +50 minutes")).'-06',
            'price' => 8500,
            'duration_minutes' => 45,
            'cancellation_deadline_hours' => 4,
        ]);

        return $id;
    }

    /** Insert an account and return its identifier. */
    private function insertUser(string $email): string
    {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'name' => $email, 'email' => $email, 'password' => 'hash']);

        return $id;
    }
}
