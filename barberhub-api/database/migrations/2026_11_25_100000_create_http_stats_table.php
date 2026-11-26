<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the minute-by-minute statistics of the requests for the monitoring panel (RNF-15, ADR 0013).
     *
     * It is a technical table: it has no barbershop and no row-level security, like `jobs`. It keeps only the pattern of the route (never
     * the URL), the method, the class of the status, the duration and the CLASS of the exception (never its message), so it holds no personal
     * data. `exception_class` is an empty string, not NULL, when nothing was thrown, so that it can be part of the primary key.
     */
    public function up(): void
    {
        Schema::create('http_stats', function (Blueprint $table): void {
            $table->timestampTz('bucket_minute');
            $table->string('method', 10);
            $table->string('route_pattern', 200);
            $table->smallInteger('status_class');
            $table->string('exception_class', 200)->default('');
            $table->integer('requests');
            $table->bigInteger('total_ms');
            $table->integer('max_ms');

            $table->primary(['bucket_minute', 'method', 'route_pattern', 'status_class', 'exception_class']);
        });

        DB::statement('ALTER TABLE http_stats ADD CONSTRAINT http_stats_status_class_check CHECK (status_class BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE http_stats ADD CONSTRAINT http_stats_requests_check CHECK (requests > 0)');
        DB::statement('ALTER TABLE http_stats ADD CONSTRAINT http_stats_durations_check CHECK (total_ms >= 0 AND max_ms >= 0 AND max_ms <= total_ms)');
    }

    /** Drop the table. */
    public function down(): void
    {
        Schema::dropIfExists('http_stats');
    }
};
