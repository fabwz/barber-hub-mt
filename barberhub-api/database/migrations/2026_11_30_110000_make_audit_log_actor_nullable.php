<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let the technical record name the system as the author of an entry (SE-04, PL-12): a null `actor_user_id` means the system, the
     * same way a null `changed_by_user_id` does in the history of the reservations. The daily task that blocks a barbershop has no
     * person. Dropping NOT NULL does not update any row, so the append-only trigger and the grants stay as they were.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_log ALTER COLUMN actor_user_id DROP NOT NULL');
    }

    /**
     * Require an author again. The record only grows and cannot be edited, so PostgreSQL refuses while entries written by the system
     * exist, instead of losing them.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE audit_log ALTER COLUMN actor_user_id SET NOT NULL');
    }
};
