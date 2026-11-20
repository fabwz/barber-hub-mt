<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the offers of the waitlist: the space a collaborator freed, proposed to one row of the line for 15 minutes (LE-03, LE-04, MT-04).
     *
     * The foreign keys carry the barbershop, so an offer can only point at a row and a collaborator of its own barbershop. While an offer
     * is pending its space is not shown to other people (DI-05), but that rule crosses two tables (reservations and offers), so no
     * EXCLUDE can state it: the use cases keep it, under the same lock of the collaborator that the reservations take.
     */
    public function up(): void
    {
        Schema::create('waitlist_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('waitlist_entry_id');
            $table->uuid('collaborator_id');
            // The space offered: the whole interval the freed reservation held, cleaning included.
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            // 15 minutes after the offer was created (LE-04).
            $table->timestampTz('expires_at');
            $table->string('status', 10);
            // Only an answer of the customer (accepted or rejected) has an instant; an expired offer was never answered.
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            $table->foreign(['waitlist_entry_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('waitlist_entries');
            $table->foreign(['collaborator_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('collaborators');
        });

        DB::statement("ALTER TABLE waitlist_offers ADD CONSTRAINT waitlist_offers_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'expired'))");
        DB::statement('ALTER TABLE waitlist_offers ADD CONSTRAINT waitlist_offers_interval_check CHECK (starts_at < ends_at)');
        DB::statement('ALTER TABLE waitlist_offers ADD CONSTRAINT waitlist_offers_expiry_check CHECK (expires_at > created_at)');
        DB::statement("ALTER TABLE waitlist_offers ADD CONSTRAINT waitlist_offers_responded_check CHECK ((status IN ('accepted', 'rejected')) = (responded_at IS NOT NULL))");
        // The spaces held by pending offers of some collaborators in a window, which the availability subtracts (DI-05).
        DB::statement("CREATE INDEX waitlist_offers_pending_idx ON waitlist_offers (barbershop_id, collaborator_id, starts_at) WHERE status = 'pending'");
        // The offers of a row of the line, to know whether it already had this space and to find its pending one.
        DB::statement('CREATE INDEX waitlist_offers_entry_idx ON waitlist_offers (barbershop_id, waitlist_entry_id)');

        DB::statement('ALTER TABLE waitlist_offers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE waitlist_offers FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY waitlist_offers_barbershop_isolation ON waitlist_offers
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security, and drop the table with its constraints and indexes. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS waitlist_offers_barbershop_isolation ON waitlist_offers');
        DB::statement('ALTER TABLE waitlist_offers NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE waitlist_offers DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('waitlist_offers');
    }
};
