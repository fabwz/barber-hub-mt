<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The migration of the images adds three nullable columns and removes them on the way back (RF-46). */
final class ImagesMigrationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string MIGRATION = '2026_11_23_100000_add_images_to_barbershops_and_services';

    /** Rolling back drops the paths of the logo, the banner and the image of the services; applying again brings them back, empty. */
    public function test_the_migration_rolls_back_and_applies_again(): void
    {
        $schema = Schema::connection('pgsql_admin');

        $this->assertTrue($schema->hasColumns('barbershops', ['logo_path', 'banner_path']));
        $this->assertTrue($schema->hasColumn('services', 'image_path'));

        $this->asOwner(fn () => $this->apply('down'));

        $this->assertFalse($schema->hasColumn('barbershops', 'logo_path'));
        $this->assertFalse($schema->hasColumn('barbershops', 'banner_path'));
        $this->assertFalse($schema->hasColumn('services', 'image_path'));

        $this->asOwner(fn () => $this->apply('up'));

        $this->assertTrue($schema->hasColumns('barbershops', ['logo_path', 'banner_path']));
        $this->assertTrue($schema->hasColumn('services', 'image_path'));
    }

    /** The columns are optional: a barbershop and a service without an image keep working. */
    public function test_the_columns_are_nullable(): void
    {
        $columns = DB::connection('pgsql_admin')->select("select table_name, column_name, is_nullable from information_schema.columns where column_name in ('logo_path', 'banner_path', 'image_path') order by table_name, column_name");

        $this->assertCount(3, $columns);

        foreach ($columns as $column) {
            $this->assertSame('YES', $column->is_nullable, "{$column->table_name}.{$column->column_name} must admit null");
        }
    }

    /** Run the migration in one direction. */
    private function apply(string $direction): void
    {
        $migration = require base_path('database/migrations/'.self::MIGRATION.'.php');

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
