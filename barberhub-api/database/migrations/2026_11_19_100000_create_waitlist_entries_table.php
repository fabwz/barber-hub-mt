<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the rows of the waitlist: what a customer waits for, a service on a day with one collaborator or any (LE-01, LE-02, MT-04).
     *
     * The foreign keys carry the barbershop, so a row can only point at a customer, a service and a collaborator of its own
     * barbershop. A null collaborator means "any" and is not checked, as a composite foreign key skips a row with a null column.
     * The most rows a customer may have active (3, LE-01) is checked by the use case, not by the database.
     */
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('customer_membership_id');
            $table->uuid('service_id');
            // Null means "any collaborator".
            $table->uuid('collaborator_id')->nullable();
            $table->date('desired_date');
            $table->string('status', 10);
            // Orders the row: first to join, first to receive an offer (LE-02).
            $table->timestampTz('joined_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            $table->foreign(['customer_membership_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('memberships');
            $table->foreign(['service_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('services');
            $table->foreign(['collaborator_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('collaborators');
        });

        DB::statement("ALTER TABLE waitlist_entries ADD CONSTRAINT waitlist_entries_status_check CHECK (status IN ('waiting', 'offered', 'fulfilled', 'left'))");
        // The active rows of a customer, to count them against the limit and to list them (LE-01).
        DB::statement("CREATE INDEX waitlist_entries_customer_active_idx ON waitlist_entries (barbershop_id, customer_membership_id, joined_at) WHERE status IN ('waiting', 'offered')");
        // The line of a service on a day, in the order of joining (LE-02).
        DB::statement("CREATE INDEX waitlist_entries_queue_idx ON waitlist_entries (barbershop_id, service_id, desired_date, joined_at) WHERE status = 'waiting'");

        DB::statement('ALTER TABLE waitlist_entries ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE waitlist_entries FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY waitlist_entries_barbershop_isolation ON waitlist_entries
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security, and drop the table with its constraints and indexes. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS waitlist_entries_barbershop_isolation ON waitlist_entries');
        DB::statement('ALTER TABLE waitlist_entries NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE waitlist_entries DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('waitlist_entries');
    }
};
