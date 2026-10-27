<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark the approved payments that must be given back because the reservation they paid could not be confirmed (PA-07).
     * Only an approved payment can carry the mark.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->boolean('refund_pending')->default(false);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_refund_pending_check CHECK (NOT refund_pending OR status = 'approved')");
    }

    /** Remove the mark and its constraint. */
    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_refund_pending_check');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('refund_pending');
        });
    }
};
