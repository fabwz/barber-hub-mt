<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\OpeningHours;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class OpeningHoursSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    /** Two barbershops, and the queries of the test reach the first one. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** The table has the columns of this issue and no others. */
    public function test_table_has_only_the_columns_of_this_issue(): void
    {
        $columns = Schema::connection('pgsql_admin')->getColumnListing('opening_hours');

        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'day_of_week', 'opens_at', 'closes_at', 'created_at', 'updated_at'], $columns);
    }

    /** The application role stores a slot with a version 7 identifier and timestamps by default; the hours have no time zone. */
    public function test_application_role_stores_a_slot_with_its_defaults(): void
    {
        $this->insertSlot($this->puraNavaja, 1, '09:00', '12:00');

        $slot = DB::table('opening_hours')->sole();

        $this->assertTrue(Str::isUuid($slot->id, 7));
        $this->assertNotNull($slot->created_at);
        $this->assertNotNull($slot->updated_at);
        $this->assertSame('09:00:00', $slot->opens_at);
        $this->assertSame('12:00:00', $slot->closes_at);
        $this->assertSame(
            ['time without time zone', 'time without time zone'],
            [$this->dataType('opens_at'), $this->dataType('closes_at')],
        );
    }

    /** The extension and the range type of the exclusion exist, and the owner of the tables created them. */
    public function test_extension_and_range_type_exist(): void
    {
        $admin = DB::connection('pgsql_admin');

        $this->assertSame(1, $admin->table('pg_extension')->where('extname', 'btree_gist')->count());
        $this->assertSame(1, $admin->table('pg_type')->where('typname', 'timerange')->count());
        $this->assertSame('[09:00:00,12:00:00)', $admin->scalar("select timerange('09:00', '12:00')::text"));
    }

    /** The database accepts a slot from Monday to Sunday. */
    public function test_database_accepts_every_day_of_the_week(): void
    {
        foreach (range(1, 7) as $day) {
            $this->insertSlot($this->puraNavaja, $day, '09:00', '12:00');
        }

        $this->assertSame(7, DB::table('opening_hours')->count());
    }

    /**
     * Rows the checks refuse: the day out of 1 to 7 and the closing that is not after the opening.
     *
     * @return array<string, array{int, string, string, string}>
     */
    public static function invalidRows(): array
    {
        return [
            'day 0' => [0, '09:00', '12:00', 'opening_hours_day_of_week_check'],
            'day 8' => [8, '09:00', '12:00', 'opening_hours_day_of_week_check'],
            'closing equal to the opening' => [1, '09:00', '09:00', 'opening_hours_opens_at_check'],
            'closing before the opening' => [1, '12:00', '09:00', 'opening_hours_opens_at_check'],
        ];
    }

    /** A row that breaks a range of the business is refused by the database (HO-01). */
    #[DataProvider('invalidRows')]
    public function test_database_rejects_an_invalid_row(int $day, string $opens, string $closes, string $constraint): void
    {
        try {
            DB::transaction(fn () => $this->insertSlot($this->puraNavaja, $day, $opens, $closes));
            $this->fail('An invalid slot was accepted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
            $this->assertSame(0, DB::table('opening_hours')->count());
        }
    }

    /**
     * Slots that share some minute with 09:00-12:00 on Monday.
     *
     * @return array<string, array{string, string}>
     */
    public static function overlappingSlots(): array
    {
        return [
            'identical' => ['09:00', '12:00'],
            'starts inside' => ['11:59', '14:00'],
            'ends inside' => ['08:00', '09:01'],
            'contained' => ['10:00', '11:00'],
            'contains it' => ['08:00', '18:00'],
        ];
    }

    /** Two slots that overlap on the same day of the same barbershop are refused with the exclusion SQLSTATE (HO-01). */
    #[DataProvider('overlappingSlots')]
    public function test_database_rejects_overlapping_slots_of_a_day(string $opens, string $closes): void
    {
        $this->insertSlot($this->puraNavaja, 1, '09:00', '12:00');

        try {
            DB::transaction(fn () => $this->insertSlot($this->puraNavaja, 1, $opens, $closes));
            $this->fail("The slot {$opens}-{$closes} was accepted.");
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->getCode());
            $this->assertStringContainsString('opening_hours_no_overlap_excl', $exception->getMessage());
            $this->assertSame(1, DB::table('opening_hours')->count());
        }
    }

    /** Slots that only touch, 09:00-12:00 and 12:00-15:00, are allowed: the range excludes its end (HO-01). */
    public function test_database_allows_contiguous_slots(): void
    {
        $this->insertSlot($this->puraNavaja, 1, '09:00', '12:00');
        $this->insertSlot($this->puraNavaja, 1, '12:00', '15:00');
        $this->insertSlot($this->puraNavaja, 1, '08:00', '09:00');

        $this->assertSame(3, DB::table('opening_hours')->count());
    }

    /** The same hours on another day, or in another barbershop, do not overlap. */
    public function test_the_same_hours_on_another_day_or_barbershop_do_not_overlap(): void
    {
        $this->insertSlot($this->puraNavaja, 1, '09:00', '12:00');
        $this->insertSlot($this->puraNavaja, 2, '09:00', '12:00');

        $this->scopeQueriesTo($this->fadeLab);
        $this->insertSlot($this->fadeLab, 1, '09:00', '12:00');

        $this->assertSame(1, DB::table('opening_hours')->count());
    }

    /** The key (id, barbershop_id) exists for the composite foreign keys of the later tables. */
    public function test_table_has_the_composite_candidate_key(): void
    {
        $indexes = array_column(Schema::connection('pgsql_admin')->getIndexes('opening_hours'), 'columns', 'name');

        $this->assertContains(['id', 'barbershop_id'], array_values($indexes));
    }

    /** Deleting a barbershop deletes its schedule. */
    public function test_slots_are_deleted_with_their_barbershop(): void
    {
        $this->insertSlot($this->puraNavaja, 1, '09:00', '12:00');

        DB::table('barbershops')->where('id', $this->puraNavaja)->delete();

        $this->assertSame(0, DB::connection('pgsql_admin')->table('opening_hours')->count());
    }

    /** The queries of a barbershop see its slots and not those of another one, and without a barbershop none is visible (MT-06). */
    public function test_a_barbershop_only_sees_its_own_slots(): void
    {
        $own = $this->insertSlot($this->puraNavaja, 1, '09:00', '12:00');
        $this->scopeQueriesTo($this->fadeLab);
        $other = $this->insertSlot($this->fadeLab, 1, '09:00', '12:00');

        $this->assertSame([$other], DB::table('opening_hours')->pluck('id')->all());

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$own], DB::table('opening_hours')->pluck('id')->all());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('opening_hours')->count());
    }

    /** Writing a slot of another barbershop is refused by the policy with the SQLSTATE of a privilege violation. */
    public function test_policy_rejects_inserting_a_slot_for_another_barbershop(): void
    {
        try {
            DB::transaction(fn () => $this->insertSlot($this->fadeLab, 1, '09:00', '12:00'));
            $this->fail('A slot of another barbershop was inserted.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    /** The policy is the only one of the table, enabled and forced, so it binds the owner of the table too. */
    public function test_table_has_its_isolation_policy_enabled_and_forced(): void
    {
        $security = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where oid = 'opening_hours'::regclass");
        $policies = DB::select("select policyname from pg_policies where schemaname = 'public' and tablename = 'opening_hours'");

        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertSame(['opening_hours_barbershop_isolation'], array_column($policies, 'policyname'));
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert a slot straight into the table and return its identifier. */
    private function insertSlot(string $barbershopId, int $day, string $opens, string $closes): string
    {
        $id = (string) Str::uuid7();
        DB::table('opening_hours')->insert([
            'id' => $id,
            'barbershop_id' => $barbershopId,
            'day_of_week' => $day,
            'opens_at' => $opens,
            'closes_at' => $closes,
        ]);

        return $id;
    }

    /** Data type PostgreSQL reports for a column of the table. */
    private function dataType(string $column): string
    {
        return (string) DB::connection('pgsql_admin')->scalar(
            "select data_type from information_schema.columns where table_name = 'opening_hours' and column_name = ?",
            [$column],
        );
    }
}
