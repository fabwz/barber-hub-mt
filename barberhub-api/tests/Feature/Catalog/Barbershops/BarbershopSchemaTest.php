<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class BarbershopSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** The table has the columns of the registration, the public link and the gateway, and none that belong to later issues. */
    public function test_table_has_only_the_columns_of_this_issue(): void
    {
        $columns = Schema::connection('pgsql_admin')->getColumnListing('barbershops');

        $this->assertEqualsCanonicalizing(['id', 'name', 'city', 'phone', 'public_link', 'payment_gateway', 'payment_mode', 'deposit_percentage', 'cancellation_deadline_hours', 'created_at', 'updated_at'], $columns);
    }

    /** The application role stores a barbershop with a version 7 identifier and timestamps. */
    public function test_application_role_stores_a_barbershop(): void
    {
        $this->insertBarbershop();

        $barbershop = DB::table('barbershops')->sole();

        $this->assertTrue(Str::isUuid($barbershop->id, 7));
        $this->assertSame('pura-navaja', $barbershop->public_link);
        $this->assertNotNull($barbershop->created_at);
        $this->assertNotNull($barbershop->updated_at);
    }

    /** Name, city, phone and public link are required. */
    public function test_database_rejects_a_barbershop_without_each_required_column(): void
    {
        $row = ['name' => 'Pura Navaja', 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => 'pura-navaja'];

        foreach (array_keys($row) as $column) {
            try {
                DB::transaction(fn () => DB::table('barbershops')->insert(array_diff_key($row, [$column => true])));
                $this->fail("A barbershop without {$column} was accepted.");
            } catch (QueryException) {
                $this->assertDatabaseCount('barbershops', 0);
            }
        }
    }

    /** Two barbershops never share a link, whatever their name (MT-07). */
    public function test_database_rejects_a_repeated_link(): void
    {
        $this->insertBarbershop();

        try {
            DB::transaction(fn () => $this->insertBarbershop());
            $this->fail('Two barbershops with the same link were accepted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('barbershops_public_link_unique', $exception->getMessage());
            $this->assertDatabaseCount('barbershops', 1);
        }
    }

    /** The link must be lowercase letters and digits joined by single hyphens. */
    #[DataProvider('malformedLinks')]
    public function test_database_rejects_a_malformed_link(string $link): void
    {
        try {
            DB::transaction(fn () => $this->insertBarbershop($link));
            $this->fail("The link \"{$link}\" was accepted.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString('barbershops_public_link_format_check', $exception->getMessage());
            $this->assertDatabaseCount('barbershops', 0);
        }
    }

    /**
     * Links the format check refuses.
     *
     * @return array<string, array{string}>
     */
    public static function malformedLinks(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Pura-Navaja'],
            'space' => ['pura navaja'],
            'accent' => ['barbería'],
            'leading hyphen' => ['-pura'],
            'trailing hyphen' => ['pura-'],
            'double hyphen' => ['pura--navaja'],
            'trailing newline' => ["pura\n"],
        ];
    }

    /** Insert a barbershop straight into the table. */
    private function insertBarbershop(string $link = 'pura-navaja'): void
    {
        DB::table('barbershops')->insert(['name' => 'Pura Navaja', 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);
    }
}
