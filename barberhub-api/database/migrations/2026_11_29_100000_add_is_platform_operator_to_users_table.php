<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark the accounts that are platform operators (RF-55, PL-01). The condition belongs to the account, not to a barbershop: it is not a
     * role and has no membership. Nothing in the API writes it; the artisan command of the operator is the only way to create one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_operator')->default(false);
        });
    }

    /** Remove the mark. */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_operator');
        });
    }
};
