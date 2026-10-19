<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the global user accounts table; roles live in the memberships (UP-01). */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('name', 120);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('terms_accepted_at')->nullable();
            $table->timestampTz('privacy_accepted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    /** Drop the user accounts table. */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
