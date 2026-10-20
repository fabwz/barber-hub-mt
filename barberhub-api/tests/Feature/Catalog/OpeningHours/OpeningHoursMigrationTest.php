<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\OpeningHours;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The migrations of the schedule and the closures roll back without leftovers, so migrate:fresh keeps working (ADR 0009). */
final class OpeningHoursMigrationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string OPENING_HOURS = '2026_10_20_110000_create_opening_hours_table';

    private const string CLOSURES = '2026_10_20_120000_create_closures_table';

    // The shifts and breaks of the collaborators use the range type, so they go first when rolling back.
    private const string COLLABORATORS = '2026_10_20_140000_create_collaborators_tables';

    /** Rolling back drops both tables, the range type and the extension; applying again brings them back. */
    public function test_migrations_roll_back_and_apply_again_without_leftovers(): void
    {
        $admin = DB::connection('pgsql_admin');
        $schema = Schema::connection('pgsql_admin');

        $this->asOwner(function (): void {
            $this->apply(self::COLLABORATORS, 'down');
            $this->apply(self::CLOSURES, 'down');
            $this->apply(self::OPENING_HOURS, 'down');
        });

        $this->assertFalse($schema->hasTable('opening_hours'));
        $this->assertFalse($schema->hasTable('closures'));
        $this->assertSame(0, $admin->table('pg_type')->where('typname', 'timerange')->count());
        $this->assertSame(0, $admin->table('pg_extension')->where('extname', 'btree_gist')->count());

        $this->asOwner(function (): void {
            $this->apply(self::OPENING_HOURS, 'up');
            $this->apply(self::CLOSURES, 'up');
            $this->apply(self::COLLABORATORS, 'up');
        });

        $this->assertTrue($schema->hasTable('opening_hours'));
        $this->assertTrue($schema->hasTable('closures'));
        $this->assertSame(1, $admin->table('pg_type')->where('typname', 'timerange')->count());
        $this->assertSame(1, $admin->table('pg_extension')->where('extname', 'btree_gist')->count());
    }

    /** A type left behind by migrate:fresh, which drops the tables but not the types, does not break the migration. */
    public function test_a_leftover_type_does_not_break_the_migration(): void
    {
        $admin = DB::connection('pgsql_admin');

        $this->asOwner(function () use ($admin): void {
            $this->apply(self::COLLABORATORS, 'down');
            $this->apply(self::CLOSURES, 'down');
            $this->apply(self::OPENING_HOURS, 'down');
            $admin->statement('CREATE TYPE timerange AS RANGE (subtype = time)');

            $this->apply(self::OPENING_HOURS, 'up');
            $this->apply(self::CLOSURES, 'up');
            $this->apply(self::COLLABORATORS, 'up');
        });

        $this->assertSame(1, $admin->table('pg_type')->where('typname', 'timerange')->count());
        $this->assertTrue(Schema::connection('pgsql_admin')->hasTable('opening_hours'));
    }

    /** Run the up or down method of a migration of the project. */
    private function apply(string $name, string $direction): void
    {
        $migration = require base_path("database/migrations/{$name}.php");

        $migration->{$direction}();
    }

    /** Run the migration code through the connection of the table owner, as the migrate command does. */
    private function asOwner(Closure $operation): void
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');

        try {
            $operation();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
