<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the two switches of each barbershop: whether it sells vouchers and whether it has a waitlist (RF-09, BO-01, LE-01).
     * Both start off. The table has no row-level security: its rows are the barbershops themselves.
     */
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->boolean('vouchers_enabled')->default(false);
            $table->boolean('waitlist_enabled')->default(false);
        });
    }

    /** Remove the two switches. */
    public function down(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->dropColumn(['vouchers_enabled', 'waitlist_enabled']);
        });
    }
};
