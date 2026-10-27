<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the instant a temporary reservation expires if its payment does not arrive (RE-05, DI-05). It is filled only
     * while the reservation is temporary: any other status leaves it null.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable();
        });

        DB::statement("alter table reservations add constraint reservations_expires_at_check check (expires_at is null or status = 'temporary')");
    }

    /** Remove the column with its check. */
    public function down(): void
    {
        DB::statement('alter table reservations drop constraint reservations_expires_at_check');

        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }
};
