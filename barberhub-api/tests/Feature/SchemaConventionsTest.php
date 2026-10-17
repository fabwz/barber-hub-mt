<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class SchemaConventionsTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /**
     * Tables created by Laravel and Sanctum, which follow their own conventions.
     * Only framework tables belong here: the tables of the project are never
     * added to this list, they must follow the conventions.
     */
    private const array FRAMEWORK_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    private const string SNAKE_CASE = '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/';

    /** Tables, columns and primary keys of the migrated schema follow RD-06 and RD-07. */
    public function test_schema_follows_the_conventions(): void
    {
        $this->assertSame([], $this->violations(DB::connection('pgsql_admin')));
    }

    /** The check fails on a bad table and stays quiet on a good one. */
    public function test_check_detects_violations(): void
    {
        $admin = DB::connection('pgsql_admin');

        // PostgreSQL DDL is transactional: nothing created here survives the rollback.
        $admin->beginTransaction();

        try {
            $admin->statement('create table "BadTable" ("ID" serial primary key, "createdAt" timestamp, "Price" numeric(10, 2), ratio float8)');
            $admin->statement('create table good_table (id uuid primary key default uuidv7(), created_at timestamptz, price integer)');

            $violations = $this->violations($admin);
        } finally {
            $admin->rollBack();
        }

        $this->assertEqualsCanonicalizing([
            'table "BadTable": the name is not snake_case',
            'column "BadTable"."ID": the name is not snake_case',
            'column "BadTable"."createdAt": the name is not snake_case',
            'column "BadTable"."Price": the name is not snake_case',
            'table "BadTable": the primary key must be a single uuid column "id" with default uuidv7()',
            'column "BadTable"."createdAt": timestamp without time zone, use timestamptz',
            'column "BadTable"."Price": numeric, store integer amounts',
            'column "BadTable"."ratio": float8, store integer amounts',
        ], $violations);
    }

    /** @return list<string> */
    private function violations(ConnectionInterface $db): array
    {
        $rows = $db->select(<<<'SQL'
            select c.relname as table_name,
                   a.attname as column_name,
                   t.typname as type_name,
                   pg_get_expr(d.adbin, d.adrelid) as default_expr,
                   (pk.conkey is not null)::int as has_pk,
                   coalesce(a.attnum = any (pk.conkey), false)::int as is_pk
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            join pg_attribute a on a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped
            join pg_type t on t.oid = a.atttypid
            left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
            left join pg_constraint pk on pk.conrelid = c.oid and pk.contype = 'p'
            where n.nspname = 'public' and c.relkind in ('r', 'p')
            order by c.relname, a.attnum
            SQL);

        $violations = [];
        $tables = [];

        foreach ($rows as $row) {
            $table = $row->table_name;

            if (in_array($table, self::FRAMEWORK_TABLES, true)) {
                continue;
            }

            $tables[$table]['has_pk'] = (bool) $row->has_pk;

            if ((bool) $row->is_pk) {
                $tables[$table]['pk'][] = [$row->column_name, $row->type_name, $row->default_expr];
            }

            if (! preg_match(self::SNAKE_CASE, $row->column_name)) {
                $violations[] = sprintf('column "%s"."%s": the name is not snake_case', $table, $row->column_name);
            }

            if ($row->type_name === 'timestamp') {
                $violations[] = sprintf('column "%s"."%s": timestamp without time zone, use timestamptz', $table, $row->column_name);
            }

            if (in_array($row->type_name, ['numeric', 'float4', 'float8'], true)) {
                $violations[] = sprintf('column "%s"."%s": %s, store integer amounts', $table, $row->column_name, $row->type_name);
            }
        }

        foreach ($tables as $table => $info) {
            if (! preg_match(self::SNAKE_CASE, (string) $table)) {
                $violations[] = sprintf('table "%s": the name is not snake_case', $table);
            }

            if (($info['pk'] ?? null) !== [['id', 'uuid', 'uuidv7()']]) {
                $violations[] = sprintf('table "%s": the primary key must be a single uuid column "id" with default uuidv7()', $table);
            }
        }

        return $violations;
    }
}
