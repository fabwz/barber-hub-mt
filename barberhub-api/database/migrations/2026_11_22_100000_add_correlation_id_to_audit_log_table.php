<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link each entry of the technical record to the log through the correlation identifier of the request that made it (RNF-25, SE-04).
     *
     * The column admits nulls because the entries written before it existed have none; the application always fills it. Adding a column
     * is not an UPDATE of a row, so the append-only trigger and the grants do not change.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->uuid('correlation_id')->nullable()->after('changes');
        });
    }

    /** Drop the column. */
    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropColumn('correlation_id');
        });
    }
};
