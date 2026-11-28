<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the lock of a barbershop for unpaid rent (RF-58, PL-07). `blocked_at` is the instant the platform blocked it, or null while it
     * is not blocked: the state is the column, and nothing else stores it. The pending months are not kept here either; they are counted
     * from the overdue installments (PL-05). `barbershops` has no row security, so the column needs no policy.
     */
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->timestampTz('blocked_at')->nullable();
        });
    }

    /** Drop the column. */
    public function down(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->dropColumn('blocked_at');
        });
    }
};
