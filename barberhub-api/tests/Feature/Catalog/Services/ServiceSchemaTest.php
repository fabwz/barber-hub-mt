<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class ServiceSchemaTest extends TestCase
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

    /** The table has the columns of this issue and none that belong to later ones (the image is #46). */
    public function test_table_has_only_the_columns_of_this_issue(): void
    {
        $columns = Schema::connection('pgsql_admin')->getColumnListing('services');

        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'name', 'duration_minutes', 'price', 'is_active', 'created_at', 'updated_at'], $columns);
    }

    /** The application role stores a service with a version 7 identifier, active and with timestamps by default. */
    public function test_application_role_stores_a_service_with_its_defaults(): void
    {
        $this->insertService($this->puraNavaja);

        $service = DB::table('services')->sole();

        $this->assertTrue(Str::isUuid($service->id, 7));
        $this->assertTrue($service->is_active);
        $this->assertNotNull($service->created_at);
        $this->assertNotNull($service->updated_at);
    }

    /** The limits of the duration and of the price are accepted (SV-01). */
    public function test_database_accepts_the_limits(): void
    {
        foreach ([10, 180] as $minutes) {
            $this->insertService($this->puraNavaja, ['duration_minutes' => $minutes]);
        }

        $this->insertService($this->puraNavaja, ['price' => 1]);

        $this->assertSame(3, DB::table('services')->count());
    }

    /**
     * Durations the check refuses.
     *
     * @return array<string, array{int}>
     */
    public static function invalidDurations(): array
    {
        return ['seven' => [7], 'between steps' => [12], 'zero' => [0], 'one over the maximum' => [185], 'two hundred' => [200]];
    }

    /** A duration out of 10 to 180 minutes or between steps of 5 is refused by the database (SV-01). */
    #[DataProvider('invalidDurations')]
    public function test_database_rejects_an_invalid_duration(int $minutes): void
    {
        try {
            DB::transaction(fn () => $this->insertService($this->puraNavaja, ['duration_minutes' => $minutes]));
            $this->fail("A duration of {$minutes} minutes was accepted.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString('services_duration_minutes_check', $exception->getMessage());
            $this->assertSame(0, DB::table('services')->count());
        }
    }

    /** A price of zero or less is refused by the database (SV-01). */
    public function test_database_rejects_a_price_that_is_not_above_zero(): void
    {
        foreach ([0, -7000] as $price) {
            try {
                DB::transaction(fn () => $this->insertService($this->puraNavaja, ['price' => $price]));
                $this->fail("A price of {$price} colones was accepted.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('services_price_check', $exception->getMessage());
            }
        }

        $this->assertSame(0, DB::table('services')->count());
    }

    /** A name longer than 120 characters, and a service without name, duration or price, are refused. */
    public function test_database_rejects_missing_and_oversized_data(): void
    {
        $row = ['name' => 'Corte clásico', 'duration_minutes' => 30, 'price' => 7000];

        foreach (array_keys($row) as $column) {
            try {
                DB::transaction(fn () => DB::table('services')->insert(['barbershop_id' => $this->puraNavaja, ...array_diff_key($row, [$column => true])]));
                $this->fail("A service without {$column} was accepted.");
            } catch (QueryException) {
                $this->assertSame(0, DB::table('services')->count());
            }
        }

        $this->expectException(QueryException::class);
        DB::transaction(fn () => $this->insertService($this->puraNavaja, ['name' => str_repeat('a', 121)]));
    }

    /** A service belongs to a barbershop that exists. */
    public function test_database_rejects_a_service_of_an_unknown_barbershop(): void
    {
        $unknown = (string) Str::uuid7();
        $this->scopeQueriesTo($unknown);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('services_barbershop_id_foreign');

        DB::transaction(fn () => $this->insertService($unknown));
    }

    /** The key (id, barbershop_id) exists for the composite foreign keys of the later tables. */
    public function test_table_has_the_composite_candidate_key(): void
    {
        $indexes = array_column(Schema::connection('pgsql_admin')->getIndexes('services'), 'columns', 'name');

        $this->assertContains(['id', 'barbershop_id'], array_values($indexes));
    }

    /** Deleting a barbershop deletes its services. */
    public function test_services_are_deleted_with_their_barbershop(): void
    {
        $this->insertService($this->puraNavaja);

        DB::table('barbershops')->where('id', $this->puraNavaja)->delete();

        $this->assertSame(0, DB::connection('pgsql_admin')->table('services')->count());
    }

    /** The queries of a barbershop see its services and not those of another one, even without a filter (MT-06). */
    public function test_a_barbershop_only_sees_its_own_services(): void
    {
        $own = $this->insertService($this->puraNavaja);
        $this->scopeQueriesTo($this->fadeLab);
        $other = $this->insertService($this->fadeLab);

        $this->assertSame([$other], DB::table('services')->pluck('id')->all());

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$own], DB::table('services')->pluck('id')->all());
        $this->assertNull(DB::table('services')->where('id', $other)->first());
    }

    /** With no barbershop set no service is visible. */
    public function test_without_a_barbershop_nothing_is_visible(): void
    {
        $this->insertService($this->puraNavaja);
        $this->scopeQueriesTo(null);

        $this->assertSame(0, DB::table('services')->count());
    }

    /** Writing a row of another barbershop is refused by the policy with the SQLSTATE of a privilege violation. */
    public function test_policy_rejects_inserting_a_service_for_another_barbershop(): void
    {
        try {
            DB::transaction(fn () => $this->insertService($this->fadeLab));
            $this->fail('A service of another barbershop was inserted.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    /** Moving a service to another barbershop is refused by the policy, and the row stays where it was. */
    public function test_policy_rejects_moving_a_service_to_another_barbershop(): void
    {
        $id = $this->insertService($this->puraNavaja);

        try {
            DB::transaction(fn () => DB::table('services')->where('id', $id)->update(['barbershop_id' => $this->fadeLab]));
            $this->fail('A service was moved to another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }

        $this->assertSame($this->puraNavaja, DB::table('services')->where('id', $id)->value('barbershop_id'));
    }

    /** The policy is the only one of the table, enabled and forced, so it binds the owner of the table too. */
    public function test_table_has_its_isolation_policy_enabled_and_forced(): void
    {
        $security = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where oid = 'services'::regclass");
        $policies = DB::select("select policyname from pg_policies where schemaname = 'public' and tablename = 'services'");

        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertSame(['services_barbershop_isolation'], array_column($policies, 'policyname'));
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /**
     * Insert a service straight into the table and return its identifier.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertService(string $barbershopId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert([
            'id' => $id,
            'barbershop_id' => $barbershopId,
            'name' => 'Corte clásico',
            'duration_minutes' => 30,
            'price' => 7000,
            ...$overrides,
        ]);

        return $id;
    }
}
