<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the switch that makes a barbershop appear in the public directory (RF-54, MT-10). It starts off: none appears until its
     * owner says so. The table has no row-level security: its rows are the barbershops themselves.
     */
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->boolean('listed_in_directory')->default(false);
        });
    }

    /** Remove the switch. */
    public function down(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->dropColumn('listed_in_directory');
        });
    }
};
