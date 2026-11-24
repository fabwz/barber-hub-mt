<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the preference of the account to receive the reminders of its reservations by email (RF-47, NO-10). It is on by default and
     * only the customer role looks at it: the confirmation, the offer and the notices of the barbershop cannot be turned off.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('reminders_enabled')->default(true);
        });
    }

    /** Remove the preference. */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('reminders_enabled');
        });
    }
};
