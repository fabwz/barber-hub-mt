<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $role = $this->applicationRole();
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $role = $this->applicationRole();
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM {$role}");
    }

    /**
     * The application role comes from the regular connection, even when the
     * migration runs through the administrative one.
     */
    private function applicationRole(): string
    {
        $name = config('database.connections.pgsql.username');

        return '"'.str_replace('"', '""', is_string($name) ? $name : '').'"';
    }
};
