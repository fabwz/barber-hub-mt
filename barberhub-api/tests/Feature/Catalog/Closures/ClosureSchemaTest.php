<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Closures;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class ClosureSchemaTest extends TestCase
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
        $columns = Schema::connection('pgsql_admin')->getColumnListing('closures');

        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'closed_on', 'reason', 'created_at', 'updated_at'], $columns);
    }

    /** The application role stores a closure with a version 7 identifier and timestamps by default; the reason is optional. */
    public function test_application_role_stores_a_closure_with_its_defaults(): void
    {
        $this->insertClosure($this->puraNavaja, '2026-12-25');

        $closure = DB::table('closures')->sole();

        $this->assertTrue(Str::isUuid($closure->id, 7));
        $this->assertNotNull($closure->created_at);
        $this->assertNotNull($closure->updated_at);
        $this->assertNull($closure->reason);
        $this->assertSame('2026-12-25', $closure->closed_on);
    }

    /** A barbershop closes a date once: a second closure on it is refused by the unique key. */
    public function test_database_rejects_a_second_closure_on_the_same_date(): void
    {
        $this->insertClosure($this->puraNavaja, '2026-12-25');

        try {
            DB::transaction(fn () => $this->insertClosure($this->puraNavaja, '2026-12-25', 'Otra vez'));
            $this->fail('A second closure on the same date was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $this->assertStringContainsString('closures_barbershop_id_closed_on_unique', $exception->getMessage());
            $this->assertSame(1, DB::table('closures')->count());
        }
    }

    /** Another date of the same barbershop, and the same date of another barbershop, are allowed. */
    public function test_another_date_or_another_barbershop_may_close(): void
    {
        $this->insertClosure($this->puraNavaja, '2026-12-25');
        $this->insertClosure($this->puraNavaja, '2026-12-26');

        $this->scopeQueriesTo($this->fadeLab);
        $this->insertClosure($this->fadeLab, '2026-12-25');

        $this->assertSame(1, DB::table('closures')->count());
    }

    /** The reason takes 200 characters and no more. */
    public function test_reason_is_limited_to_two_hundred_characters(): void
    {
        $this->insertClosure($this->puraNavaja, '2026-12-25', str_repeat('a', 200));

        $this->expectException(QueryException::class);
        DB::transaction(fn () => $this->insertClosure($this->puraNavaja, '2026-12-26', str_repeat('a', 201)));
    }

    /** A closure without a date is refused. */
    public function test_database_rejects_a_closure_without_a_date(): void
    {
        $this->expectException(QueryException::class);

        DB::transaction(fn () => DB::table('closures')->insert(['barbershop_id' => $this->puraNavaja]));
    }

    /** A closure belongs to a barbershop that exists. */
    public function test_database_rejects_a_closure_of_an_unknown_barbershop(): void
    {
        $unknown = (string) Str::uuid7();
        $this->scopeQueriesTo($unknown);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('closures_barbershop_id_foreign');

        DB::transaction(fn () => $this->insertClosure($unknown, '2026-12-25'));
    }

    /** The key (id, barbershop_id) exists for the composite foreign keys of the later tables. */
    public function test_table_has_the_composite_candidate_key(): void
    {
        $indexes = array_column(Schema::connection('pgsql_admin')->getIndexes('closures'), 'columns', 'name');

        $this->assertContains(['id', 'barbershop_id'], array_values($indexes));
        $this->assertContains(['barbershop_id', 'closed_on'], array_values($indexes));
    }

    /** Deleting a barbershop deletes its closures. */
    public function test_closures_are_deleted_with_their_barbershop(): void
    {
        $this->insertClosure($this->puraNavaja, '2026-12-25');

        DB::table('barbershops')->where('id', $this->puraNavaja)->delete();

        $this->assertSame(0, DB::connection('pgsql_admin')->table('closures')->count());
    }

    /** The queries of a barbershop see its closures and not those of another one, and without a barbershop none is visible (MT-06). */
    public function test_a_barbershop_only_sees_its_own_closures(): void
    {
        $own = $this->insertClosure($this->puraNavaja, '2026-12-25');
        $this->scopeQueriesTo($this->fadeLab);
        $other = $this->insertClosure($this->fadeLab, '2026-12-25');

        $this->assertSame([$other], DB::table('closures')->pluck('id')->all());

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$own], DB::table('closures')->pluck('id')->all());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('closures')->count());
    }

    /** Writing a closure of another barbershop is refused by the policy with the SQLSTATE of a privilege violation. */
    public function test_policy_rejects_inserting_a_closure_for_another_barbershop(): void
    {
        try {
            DB::transaction(fn () => $this->insertClosure($this->fadeLab, '2026-12-25'));
            $this->fail('A closure of another barbershop was inserted.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    /** The policy is the only one of the table, enabled and forced, so it binds the owner of the table too. */
    public function test_table_has_its_isolation_policy_enabled_and_forced(): void
    {
        $security = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where oid = 'closures'::regclass");
        $policies = DB::select("select policyname from pg_policies where schemaname = 'public' and tablename = 'closures'");

        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertSame(['closures_barbershop_isolation'], array_column($policies, 'policyname'));
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert a closure straight into the table and return its identifier. */
    private function insertClosure(string $barbershopId, string $date, ?string $reason = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('closures')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'closed_on' => $date, 'reason' => $reason]);

        return $id;
    }
}
