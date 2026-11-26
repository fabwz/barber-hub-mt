<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the links to reset a password (RF-41, SE-06). Only the SHA-256 hash of the token is stored, never the token. The primary key is
     * the account, so a new link replaces the one pending and only the last one sent works. It is an account table, global like `users`: it has
     * no barbershop and no row-level security. The row is deleted when the link is used, and with the account.
     */
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE password_reset_tokens ADD CONSTRAINT password_reset_tokens_hash_check CHECK (token_hash ~ '^[0-9a-f]{64}$')");
    }

    /** Drop the table. */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
