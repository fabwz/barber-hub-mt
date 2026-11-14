<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\TestCase;

/**
 * The integrity of the vouchers lives in PostgreSQL too: the CHECK constraints refuse what the domain would refuse, and the
 * foreign keys, which carry the barbershop, refuse a row that points across barbershops (BO-01, BO-02, BO-03, BO-09, MT-04).
 */
final class VoucherSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private const string CHECK_VIOLATION = '23514';

    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string UNIQUE_VIOLATION = '23505';

    /** Customer of Pura Navaja, of Fade Lab, and what each one has. */
    private string $membership;

    private string $otherMembership;

    private string $definition;

    private string $otherDefinition;

    private string $voucher;

    private string $otherVoucher;

    private string $reservation;

    private string $otherReservation;

    /** Both barbershops with a customer, a voucher definition, a voucher and a reservation each; the queries of the test reach Pura Navaja. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        [, $this->membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [, $this->otherMembership] = $this->insertCustomer($this->fadeLab, 'Luis Mora');
        $this->reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->membership, '2030-03-05 10:00:00-06:00');
        $this->otherReservation = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $this->otherMembership, '2030-03-05 10:00:00-06:00', minutes: 30);
        $this->definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
        $this->otherDefinition = $this->insertVoucherDefinition($this->fadeLab, [$this->corte]);
        $this->otherVoucher = $this->insertPurchasedVoucher($this->fadeLab, $this->otherMembership, $this->otherDefinition);
        $this->voucher = $this->insertPurchasedVoucher($this->puraNavaja, $this->membership, $this->definition);
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** The example of the requirements is accepted: "Bono 5 cortes", 5 sessions, ₡30.000, 90 days, with its record. */
    public function test_a_valid_voucher_is_accepted_with_its_defaults(): void
    {
        $row = DB::table('purchased_vouchers')->where('id', $this->voucher)->first();

        $this->assertNotNull($row);
        $this->assertSame([5, 5, 'active'], [$row->sessions_count, $row->remaining_sessions, $row->status]);
        $this->assertSame(5, (int) DB::table('voucher_movements')->where('purchased_voucher_id', $this->voucher)->sum('sessions_delta'));
        $this->assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', DB::table('voucher_definitions')->where('id', $this->definition)->value('id')));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidDefinitions(): array
    {
        return [
            'no sessions' => [['sessions_count' => 0]],
            'negative sessions' => [['sessions_count' => -1]],
            'free' => [['price' => 0]],
            'negative price' => [['price' => -1]],
            'no validity' => [['validity_days' => 0]],
            'blank name' => [['name' => '  ']],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidDefinitions')]
    public function test_a_definition_with_invalid_numbers_or_name_is_refused(array $override): void
    {
        $this->assertRejected(self::CHECK_VIOLATION, fn () => DB::table('voucher_definitions')->insert([
            'barbershop_id' => $this->puraNavaja, 'name' => 'Bono', 'sessions_count' => 5, 'price' => 30000, 'validity_days' => 90, ...$override,
        ]));
    }

    /** A voucher cannot cover a service of another barbershop (MT-04). */
    public function test_a_definition_cannot_cover_a_service_of_another_barbershop(): void
    {
        $this->assertRejected(self::FOREIGN_KEY_VIOLATION, fn () => DB::table('voucher_definition_services')->insert([
            'barbershop_id' => $this->puraNavaja, 'voucher_definition_id' => $this->definition, 'service_id' => $this->corte,
        ]));
    }

    /** A voucher cannot be attached to a definition of another barbershop either. */
    public function test_covered_services_need_a_definition_of_the_same_barbershop(): void
    {
        $this->assertRejected(self::FOREIGN_KEY_VIOLATION, fn () => DB::table('voucher_definition_services')->insert([
            'barbershop_id' => $this->puraNavaja, 'voucher_definition_id' => $this->otherDefinition, 'service_id' => $this->degradado,
        ]));
    }

    /** A service is covered once by a voucher. */
    public function test_a_service_is_covered_once(): void
    {
        $this->assertRejected(self::UNIQUE_VIOLATION, fn () => DB::table('voucher_definition_services')->insert([
            'barbershop_id' => $this->puraNavaja, 'voucher_definition_id' => $this->definition, 'service_id' => $this->degradado,
        ]));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidVouchers(): array
    {
        $paid = ['status' => 'active', 'activated_at' => '2030-03-01 08:00:00-06', 'expires_at' => '2030-05-30 08:00:00-06'];

        return [
            'negative balance' => [[...$paid, 'remaining_sessions' => -1]],
            'more sessions than bought' => [[...$paid, 'remaining_sessions' => 6]],
            'no sessions bought' => [[...$paid, 'sessions_count' => 0, 'remaining_sessions' => 0]],
            'free' => [[...$paid, 'price' => 0, 'remaining_sessions' => 5]],
            'no validity' => [[...$paid, 'validity_days' => 0, 'remaining_sessions' => 5]],
            'unknown status' => [[...$paid, 'status' => 'expired', 'remaining_sessions' => 5]],
            'pending with a date' => [['status' => 'pending', 'activated_at' => '2030-03-01 08:00:00-06', 'remaining_sessions' => 0]],
            'pending with sessions' => [['status' => 'pending', 'remaining_sessions' => 5]],
            'active without dates' => [['status' => 'active', 'activated_at' => null, 'expires_at' => null, 'remaining_sessions' => 5]],
            'ends before it starts' => [['status' => 'active', 'activated_at' => '2030-03-01 08:00:00-06', 'expires_at' => '2030-02-01 08:00:00-06', 'remaining_sessions' => 5]],
            'ends when it starts' => [['status' => 'active', 'activated_at' => '2030-03-01 08:00:00-06', 'expires_at' => '2030-03-01 08:00:00-06', 'remaining_sessions' => 5]],
            'blank name' => [[...$paid, 'name' => ' ', 'remaining_sessions' => 5]],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidVouchers')]
    public function test_a_purchased_voucher_that_breaks_a_rule_is_refused(array $override): void
    {
        $this->assertRejected(self::CHECK_VIOLATION, fn () => DB::table('purchased_vouchers')->insert($this->voucherRow($override)));
    }

    /** The balance of a voucher cannot be taken below zero by an update that forgot to look: the table says no (BO-03). */
    public function test_a_decrement_below_zero_is_refused_by_the_table(): void
    {
        DB::table('purchased_vouchers')->where('id', $this->voucher)->update(['remaining_sessions' => 0]);

        $this->assertRejected(self::CHECK_VIOLATION, fn () => DB::table('purchased_vouchers')->where('id', $this->voucher)->update(['remaining_sessions' => DB::raw('remaining_sessions - 1')]));
    }

    /** A voucher points at a definition and at a customer of its own barbershop and nowhere else (MT-04). */
    public function test_a_voucher_cannot_point_across_barbershops(): void
    {
        $this->assertRejected(self::FOREIGN_KEY_VIOLATION, fn () => DB::table('purchased_vouchers')->insert($this->voucherRow(['voucher_definition_id' => $this->otherDefinition])));
        $this->assertRejected(self::FOREIGN_KEY_VIOLATION, fn () => DB::table('purchased_vouchers')->insert($this->voucherRow(['customer_membership_id' => $this->otherMembership])));
    }

    /** @return array<string, array{string, int, bool}> */
    public static function invalidMovements(): array
    {
        return [
            'a hold that gives a session' => ['hold', 1, true],
            'a hold of two' => ['hold', -2, true],
            'a hold without a reservation' => ['hold', -1, false],
            'a release that takes a session' => ['release', -1, true],
            'a release without a reservation' => ['release', 1, false],
            'a consume that changes the balance' => ['consume', 1, true],
            'a consume without a reservation' => ['consume', 0, false],
            'a purchase of nothing' => ['purchase', 0, false],
            'a purchase that takes sessions' => ['purchase', -5, false],
            'a purchase with a reservation' => ['purchase', 5, true],
            'an unknown kind' => ['expire', -5, true],
        ];
    }

    #[DataProvider('invalidMovements')]
    public function test_a_movement_whose_kind_amount_and_reservation_disagree_is_refused(string $type, int $delta, bool $withReservation): void
    {
        $this->assertRejected(self::CHECK_VIOLATION, fn () => DB::table('voucher_movements')->insert([
            'barbershop_id' => $this->puraNavaja, 'purchased_voucher_id' => $this->voucher, 'type' => $type,
            'sessions_delta' => $delta, 'reservation_id' => $withReservation ? $this->reservation : null,
        ]));
    }

    /** An entry belongs to a voucher and to a reservation of its own barbershop (BO-09, MT-04). */
    public function test_a_movement_cannot_point_across_barbershops(): void
    {
        $this->assertRejected(self::FOREIGN_KEY_VIOLATION, fn () => DB::table('voucher_movements')->insert([
            'barbershop_id' => $this->puraNavaja, 'purchased_voucher_id' => $this->otherVoucher, 'type' => 'hold', 'sessions_delta' => -1, 'reservation_id' => $this->reservation,
        ]));
        $this->assertRejected(self::FOREIGN_KEY_VIOLATION, fn () => DB::table('voucher_movements')->insert([
            'barbershop_id' => $this->puraNavaja, 'purchased_voucher_id' => $this->voucher, 'type' => 'hold', 'sessions_delta' => -1, 'reservation_id' => $this->otherReservation,
        ]));
    }

    /** A voucher is activated once, and a reservation sets a session aside once and settles it once (BO-02, BO-04, BO-05). */
    public function test_the_record_gives_one_purchase_one_hold_and_one_settlement(): void
    {
        $this->assertRejected(self::UNIQUE_VIOLATION, fn () => $this->insertVoucherMovement($this->puraNavaja, $this->voucher, 'purchase', 5));

        $this->insertVoucherMovement($this->puraNavaja, $this->voucher, 'hold', -1, $this->reservation);
        $this->assertRejected(self::UNIQUE_VIOLATION, fn () => $this->insertVoucherMovement($this->puraNavaja, $this->voucher, 'hold', -1, $this->reservation));

        $this->insertVoucherMovement($this->puraNavaja, $this->voucher, 'consume', 0, $this->reservation);
        $this->assertRejected(self::UNIQUE_VIOLATION, fn () => $this->insertVoucherMovement($this->puraNavaja, $this->voucher, 'release', 1, $this->reservation));
    }

    /** The tables have the columns the model of data describes, and the record has no column to edit. */
    public function test_the_tables_have_the_columns_of_the_model(): void
    {
        $columns = fn (string $table): array => array_column(DB::connection('pgsql_admin')->select(
            "select column_name from information_schema.columns where table_schema = 'public' and table_name = ?", [$table],
        ), 'column_name');

        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'name', 'sessions_count', 'price', 'validity_days', 'is_active', 'created_at', 'updated_at'], $columns('voucher_definitions'));
        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'voucher_definition_id', 'service_id', 'created_at'], $columns('voucher_definition_services'));
        $this->assertEqualsCanonicalizing([
            'id', 'barbershop_id', 'customer_membership_id', 'voucher_definition_id', 'name', 'price', 'sessions_count', 'validity_days', 'status',
            'activated_at', 'expires_at', 'remaining_sessions', 'created_at', 'updated_at',
        ], $columns('purchased_vouchers'));
        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'purchased_voucher_id', 'reservation_id', 'type', 'sessions_delta', 'created_at'], $columns('voucher_movements'));
    }

    /** The queries the vouchers are read with have an index: the vouchers of a person, the ones about to expire and the record of a voucher. */
    public function test_the_queries_of_the_vouchers_have_their_indexes(): void
    {
        $indexes = array_column(DB::connection('pgsql_admin')->select(
            "select indexname from pg_indexes where schemaname = 'public' and tablename in ('purchased_vouchers', 'voucher_movements')",
        ), 'indexname');

        foreach (['purchased_vouchers_customer_idx', 'purchased_vouchers_expiring_idx', 'voucher_movements_voucher_idx', 'voucher_movements_reservation_idx'] as $index) {
            $this->assertContains($index, $indexes);
        }
    }

    /**
     * A row of a voucher of Pura Navaja that is valid, with the changes of a case.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function voucherRow(array $override): array
    {
        return [
            'id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'customer_membership_id' => $this->membership,
            'voucher_definition_id' => $this->definition, 'name' => 'Bono 5 cortes', 'price' => 30000, 'sessions_count' => 5, 'validity_days' => 90,
            'status' => 'active', 'activated_at' => '2030-03-01 08:00:00-06', 'expires_at' => '2030-05-30 08:00:00-06', 'remaining_sessions' => 5,
            ...$override,
        ];
    }

    /**
     * Run a write that must be rejected with the SQLSTATE, inside a savepoint so the transaction of the test goes on.
     *
     * @param  Closure(): mixed  $write
     */
    private function assertRejected(string $sqlState, Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail("The write was accepted; SQLSTATE {$sqlState} was expected.");
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, (string) $exception->getCode(), $exception->getMessage());
        }
    }
}
