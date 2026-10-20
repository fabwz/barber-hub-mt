<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Illuminate\Database\ConnectionInterface;

/** The row-level security a table of a barbershop must have (docs/database.md), to create it on a probe and to check it on every table. */
final class BarbershopIsolation
{
    public const string PROBE = 'tenancy_probe';

    /** The expression of the policy, as PostgreSQL prints it back in pg_policies. */
    public const string EXPRESSION = "(barbershop_id = (NULLIF(current_setting('app.barbershop_id'::text, true), ''::text))::uuid)";

    /**
     * Tables with a barbershop_id column that have no policy, with the reason. Only the tables that are read before
     * the barbershop is known belong here (docs/database.md, "Excepciones").
     *
     * @var list<string>
     */
    public const array EXCEPTIONS = ['memberships'];

    /**
     * Create the probe table, a minimal table of a barbershop that follows the conventions, with its rows protected.
     * It is committed, because the tests reach it from more than one connection, so it must be dropped at the end.
     */
    public static function createProbe(ConnectionInterface $admin): void
    {
        self::dropProbe($admin);

        $admin->statement(<<<'SQL'
            CREATE TABLE tenancy_probe (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                barbershop_id uuid NOT NULL,
                label varchar(50) NOT NULL
            )
            SQL);
        $admin->statement('ALTER TABLE tenancy_probe ENABLE ROW LEVEL SECURITY');
        $admin->statement('ALTER TABLE tenancy_probe FORCE ROW LEVEL SECURITY');
        $admin->statement(<<<'SQL'
            CREATE POLICY tenancy_probe_barbershop_isolation ON tenancy_probe
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Drop the probe table; a missing one is ignored. */
    public static function dropProbe(ConnectionInterface $admin): void
    {
        $admin->statement('DROP TABLE IF EXISTS tenancy_probe');
    }

    /**
     * Tables of the public schema with a barbershop_id column.
     *
     * @return list<string>
     */
    public static function tablesWithBarbershopId(ConnectionInterface $db): array
    {
        return array_column($db->select(
            "select c.table_name from information_schema.columns c
             join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name
             where c.table_schema = 'public' and c.column_name = 'barbershop_id' and t.table_type = 'BASE TABLE'
             order by c.table_name",
        ), 'table_name');
    }

    /**
     * What is missing in each table with a barbershop_id column, except the documented exceptions: row-level security
     * enabled and forced, and exactly one policy, the isolation one, with the same expression in USING and WITH CHECK.
     * A second policy is a violation too: permissive policies add up, so any extra one could open the table.
     *
     * @return list<string>
     */
    public static function violations(ConnectionInterface $db): array
    {
        $violations = [];

        foreach (array_diff(self::tablesWithBarbershopId($db), self::EXCEPTIONS) as $table) {
            $security = $db->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class where oid = ?::regclass',
                [$table],
            );
            $policies = $db->select(
                "select policyname, cmd, qual, with_check from pg_policies where schemaname = 'public' and tablename = ?",
                [$table],
            );

            if (! $security->relrowsecurity) {
                $violations[] = "{$table}: row-level security is not enabled";
            }

            if (! $security->relforcerowsecurity) {
                $violations[] = "{$table}: row-level security is not forced";
            }

            $expected = (object) [
                'policyname' => "{$table}_barbershop_isolation",
                'cmd' => 'ALL',
                'qual' => self::EXPRESSION,
                'with_check' => self::EXPRESSION,
            ];

            if (! in_array($expected, $policies, false)) {
                $violations[] = "{$table}: the policy {$table}_barbershop_isolation is missing or different";
            }

            if (count($policies) > 1) {
                $violations[] = "{$table}: has a policy nobody expects";
            }
        }

        return $violations;
    }
}
