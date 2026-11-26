<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the record of the answers of the health route for the monitoring panel (RNF-15, RNF-04, ADR 0013).
     *
     * There is one row per minute and result (which dependency failed), so a flood of calls to the public route cannot grow the table. It is a
     * technical table without barbershop or row-level security, and it holds only booleans, the state of the language provider and counts.
     */
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table): void {
            $table->timestampTz('bucket_minute');
            $table->boolean('database_ok');
            $table->boolean('queue_ok');
            $table->boolean('healthy');
            $table->string('ai_status', 20);
            $table->integer('checks');
            $table->timestampTz('last_checked_at');

            $table->primary(['bucket_minute', 'database_ok', 'queue_ok']);
        });

        DB::statement('ALTER TABLE health_checks ADD CONSTRAINT health_checks_healthy_check CHECK (healthy = (database_ok AND queue_ok))');
        DB::statement('ALTER TABLE health_checks ADD CONSTRAINT health_checks_checks_check CHECK (checks > 0)');
        DB::statement("ALTER TABLE health_checks ADD CONSTRAINT health_checks_ai_status_check CHECK (btrim(ai_status) <> '')");
    }

    /** Drop the table. */
    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
