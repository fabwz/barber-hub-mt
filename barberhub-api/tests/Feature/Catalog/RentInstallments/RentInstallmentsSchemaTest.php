<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\RentInstallments;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The table of the installments: its columns, its checks and its row security (RF-57, MT-04). The reversibility is covered by MigrationReversibilityTest. */
final class RentInstallmentsSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    /** There is no counter of pending months, only the installment, its amount, its due day, its status and its timestamps. */
    public function test_the_table_has_no_counter_of_pending_months(): void
    {
        $columns = Schema::connection('pgsql_admin')->getColumnListing('rent_installments');

        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'amount', 'due_on', 'status', 'created_at', 'updated_at'], $columns);
    }

    /** The amount is a positive whole number of colones and the status is pending or paid. */
    public function test_the_database_rejects_a_bad_amount_and_a_bad_status(): void
    {
        $id = $this->barbershop();
        $this->scopeQueriesTo($id);

        foreach ([['amount' => 0, 'status' => 'pending'], ['amount' => 1000, 'status' => 'late']] as $row) {
            try {
                DB::transaction(fn () => DB::table('rent_installments')->insert([...$row, 'barbershop_id' => $id, 'due_on' => '2026-11-10']));
                $this->fail('A bad installment was accepted.');
            } catch (QueryException) {
                $this->assertSame(0, DB::table('rent_installments')->count());
            }
        }
    }

    /** The row security hides the installments of another barbershop and refuses to write one into it. */
    public function test_a_barbershop_never_reaches_the_installments_of_another(): void
    {
        $first = $this->barbershop();
        $second = $this->barbershop();
        $this->scopeQueriesTo($first);
        $id = (string) Str::uuid7();
        DB::table('rent_installments')->insert(['id' => $id, 'barbershop_id' => $first, 'amount' => 25000, 'due_on' => '2026-11-10']);

        $this->scopeQueriesTo($second);
        $this->assertSame(0, DB::table('rent_installments')->where('id', $id)->count());

        try {
            DB::transaction(fn () => DB::table('rent_installments')->insert(['barbershop_id' => $first, 'amount' => 25000, 'due_on' => '2026-12-10']));
            $this->fail('A row of another barbershop was written.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    private function barbershop(): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => 'Barbería '.$id, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => 'b-'.$id]);

        return $id;
    }
}
