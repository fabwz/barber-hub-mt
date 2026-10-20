<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\BarbershopIsolation;
use Tests\TestCase;

/** Every table of a barbershop has its policy; a table that forgets it fails here, before it reaches production. */
final class BarbershopPolicyCatalogTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** Start with no probe table, and make sure none is left behind. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(config('database.connections.pgsql.username'), DB::scalar('select current_user'));

        BarbershopIsolation::dropProbe(DB::connection('pgsql_admin'));
        $this->beforeApplicationDestroyed(fn () => BarbershopIsolation::dropProbe(DB::connection('pgsql_admin')));
    }

    /** Every table with a barbershop_id column, except the documented exceptions, has its security enabled, forced and its policy. */
    public function test_every_table_of_a_barbershop_is_protected_by_its_policy(): void
    {
        $this->assertSame([], BarbershopIsolation::violations(DB::connection()));
    }

    /** The exceptions are tables that exist and have the column, so the list cannot go stale unnoticed. */
    public function test_the_exceptions_are_real_tables_with_a_barbershop_column(): void
    {
        $tables = BarbershopIsolation::tablesWithBarbershopId(DB::connection());

        foreach (BarbershopIsolation::EXCEPTIONS as $exception) {
            $this->assertContains($exception, $tables);
        }
    }

    /** A table that has everything is not reported, and the check does see it. */
    public function test_a_protected_table_is_seen_and_not_reported(): void
    {
        BarbershopIsolation::createProbe(DB::connection('pgsql_admin'));

        $this->assertContains(BarbershopIsolation::PROBE, BarbershopIsolation::tablesWithBarbershopId(DB::connection()));
        $this->assertSame([], BarbershopIsolation::violations(DB::connection()));
    }

    /**
     * Each way of leaving a table half protected is reported, with what it lacks.
     *
     * @param  list<string>  $statements
     */
    #[DataProvider('halfProtections')]
    public function test_a_table_without_its_protection_is_reported(string $violation, array $statements): void
    {
        $admin = DB::connection('pgsql_admin');
        BarbershopIsolation::createProbe($admin);

        foreach ($statements as $statement) {
            $admin->statement($statement);
        }

        $this->assertContains(BarbershopIsolation::PROBE.': '.$violation, BarbershopIsolation::violations(DB::connection()));
    }

    /**
     * What the check reports for each way of leaving the probe half protected, with the statements that do it.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function halfProtections(): array
    {
        $missing = 'the policy tenancy_probe_barbershop_isolation is missing or different';
        $drop = 'DROP POLICY tenancy_probe_barbershop_isolation ON tenancy_probe';

        return [
            'not enabled' => ['row-level security is not enabled', ['ALTER TABLE tenancy_probe DISABLE ROW LEVEL SECURITY']],
            'not forced' => ['row-level security is not forced', ['ALTER TABLE tenancy_probe NO FORCE ROW LEVEL SECURITY']],
            'no policy' => [$missing, [$drop]],
            'different policy' => [$missing, [$drop, 'CREATE POLICY tenancy_probe_barbershop_isolation ON tenancy_probe USING (true) WITH CHECK (true)']],
            'policy without check' => [$missing, [$drop, "CREATE POLICY tenancy_probe_barbershop_isolation ON tenancy_probe USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)"]],
            'extra policy' => ['has a policy nobody expects', ['CREATE POLICY tenancy_probe_open ON tenancy_probe USING (true)']],
        ];
    }

    /** A table with the column and nothing else is reported with everything it lacks. */
    public function test_a_table_with_nothing_is_reported_in_full(): void
    {
        DB::connection('pgsql_admin')->statement(
            'CREATE TABLE tenancy_probe (id uuid PRIMARY KEY DEFAULT uuidv7(), barbershop_id uuid NOT NULL)',
        );

        $this->assertSame([
            'tenancy_probe: row-level security is not enabled',
            'tenancy_probe: row-level security is not forced',
            'tenancy_probe: the policy tenancy_probe_barbershop_isolation is missing or different',
        ], BarbershopIsolation::violations(DB::connection()));
    }
}
