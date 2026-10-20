<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class BarbershopSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** The table has the columns of the registration and none that belong to later issues. */
    public function test_table_has_only_the_columns_of_this_issue(): void
    {
        $columns = Schema::connection('pgsql_admin')->getColumnListing('barbershops');

        $this->assertEqualsCanonicalizing(['id', 'name', 'city', 'phone', 'created_at', 'updated_at'], $columns);
    }

    /** The application role stores a barbershop with a version 7 identifier and timestamps. */
    public function test_application_role_stores_a_barbershop(): void
    {
        $this->insertBarbershop();

        $barbershop = DB::table('barbershops')->sole();

        $this->assertTrue(Str::isUuid($barbershop->id, 7));
        $this->assertNotNull($barbershop->created_at);
        $this->assertNotNull($barbershop->updated_at);
    }

    /** Name, city and phone are required. */
    public function test_database_rejects_a_barbershop_without_each_required_column(): void
    {
        $row = ['name' => 'Pura Navaja', 'city' => 'San Carlos', 'phone' => '2460-0000'];

        foreach (array_keys($row) as $column) {
            try {
                DB::transaction(fn () => DB::table('barbershops')->insert(array_diff_key($row, [$column => true])));
                $this->fail("A barbershop without {$column} was accepted.");
            } catch (QueryException) {
                $this->assertDatabaseCount('barbershops', 0);
            }
        }
    }

    /** Insert a barbershop straight into the table. */
    private function insertBarbershop(): void
    {
        DB::table('barbershops')->insert(['name' => 'Pura Navaja', 'city' => 'San Carlos', 'phone' => '2460-0000']);
    }
}
