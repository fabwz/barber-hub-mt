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

/** A barbershop never sees or touches the vouchers of another, even with a query that forgets to filter (MT-02, MT-03, MT-04). */
final class VoucherRowLevelSecurityTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private const string INSUFFICIENT_PRIVILEGE = '42501';

    private const array TABLES = ['voucher_definitions', 'voucher_definition_services', 'purchased_vouchers', 'voucher_movements'];

    /** @var array<string, array{definition: string, voucher: string, reservation: string}> */
    private array $rows = [];

    /** Each barbershop has a voucher definition, a voucher bought by its customer and a session set aside for a reservation. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();

        foreach ([[$this->puraNavaja, $this->daniela, $this->degradado, 45], [$this->fadeLab, $this->mauricio, $this->corte, 30]] as [$barbershop, $collaborator, $service, $minutes]) {
            [, $membership] = $this->insertCustomer($barbershop, 'Cliente '.$barbershop);
            $reservation = $this->insertReservation($barbershop, $collaborator, $service, $membership, '2030-03-05 10:00:00-06:00', minutes: $minutes);
            $definition = $this->insertVoucherDefinition($barbershop, [$service]);
            $voucher = $this->insertPurchasedVoucher($barbershop, $membership, $definition);
            $this->insertVoucherMovement($barbershop, $voucher, 'hold', -1, $reservation);
            $this->rows[$barbershop] = ['definition' => $definition, 'voucher' => $voucher, 'reservation' => $reservation];
        }
    }

    /** With the context of a barbershop, only its rows are visible, in each table, with no filter at all. */
    public function test_a_barbershop_sees_only_its_own_rows(): void
    {
        foreach ([$this->puraNavaja, $this->fadeLab] as $barbershop) {
            $this->scopeQueriesTo($barbershop);

            foreach (self::TABLES as $table) {
                $barbershops = DB::table($table)->pluck('barbershop_id')->unique()->all();
                $this->assertSame([$barbershop], $barbershops, "{$table} shows rows of another barbershop.");
            }

            $this->assertSame([$this->rows[$barbershop]['voucher']], DB::table('purchased_vouchers')->pluck('id')->all());
        }
    }

    /** Without a barbershop in the context, nothing is visible. */
    public function test_without_a_barbershop_no_row_is_visible(): void
    {
        $this->scopeQueriesTo(null);

        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} shows rows without a barbershop.");
        }
    }

    /** Fetching the voucher of another barbershop by its identifier finds nothing, as if it did not exist. */
    public function test_the_voucher_of_another_barbershop_cannot_be_found_by_its_id(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);

        $this->assertNull(DB::table('purchased_vouchers')->where('id', $this->rows[$this->fadeLab]['voucher'])->first());
        $this->assertNull(DB::table('voucher_definitions')->where('id', $this->rows[$this->fadeLab]['definition'])->first());
    }

    /** An update or a delete aimed at a row of another barbershop touches nothing, and the row stays as it was. */
    public function test_a_barbershop_cannot_change_or_delete_the_vouchers_of_another(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $other = $this->rows[$this->fadeLab];

        $this->assertSame(0, DB::table('purchased_vouchers')->where('id', $other['voucher'])->update(['remaining_sessions' => 0]));
        $this->assertSame(0, DB::table('voucher_definitions')->where('id', $other['definition'])->update(['is_active' => false]));
        $this->assertSame(0, DB::table('voucher_definitions')->where('id', $other['definition'])->delete());

        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame(5, DB::table('purchased_vouchers')->where('id', $other['voucher'])->value('remaining_sessions'));
        $this->assertTrue((bool) DB::table('voucher_definitions')->where('id', $other['definition'])->value('is_active'));
    }

    /** @return array<string, array{string}> */
    public static function tables(): array
    {
        return array_combine(self::TABLES, array_map(fn (string $table): array => [$table], self::TABLES));
    }

    /** A row cannot be written for another barbershop: the check of the policy refuses it. */
    #[DataProvider('tables')]
    public function test_a_row_cannot_be_written_for_another_barbershop(string $table): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $own = $this->rows[$this->puraNavaja];

        $row = match ($table) {
            'voucher_definitions' => ['name' => 'Bono', 'sessions_count' => 5, 'price' => 30000, 'validity_days' => 90],
            'voucher_definition_services' => ['voucher_definition_id' => $own['definition'], 'service_id' => $this->degradado],
            'purchased_vouchers' => ['customer_membership_id' => (string) DB::table('memberships')->where('barbershop_id', $this->puraNavaja)->where('role', 'customer')->value('id'), 'voucher_definition_id' => $own['definition'], 'name' => 'Bono', 'price' => 30000, 'sessions_count' => 5, 'validity_days' => 90, 'status' => 'pending'],
            default => ['purchased_voucher_id' => $own['voucher'], 'type' => 'consume', 'sessions_delta' => 0, 'reservation_id' => $own['reservation']],
        };

        $this->assertRefusedByPolicy(fn () => DB::table($table)->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->fadeLab, ...$row]));
    }

    /** A row cannot be moved to another barbershop by changing its owner. */
    public function test_a_voucher_cannot_be_moved_to_another_barbershop(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);

        $this->assertRefusedByPolicy(fn () => DB::table('voucher_definitions')->where('id', $this->rows[$this->puraNavaja]['definition'])->update(['barbershop_id' => $this->fadeLab]));
    }

    /**
     * Run a write that the policy must refuse, inside a savepoint so the transaction of the test goes on.
     *
     * @param  Closure(): mixed  $write
     */
    private function assertRefusedByPolicy(Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('The policy accepted a row of another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame(self::INSUFFICIENT_PRIVILEGE, (string) $exception->getCode(), $exception->getMessage());
        }
    }
}
