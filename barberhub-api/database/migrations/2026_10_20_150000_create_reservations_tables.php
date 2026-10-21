<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables of the module, parents first. Each one gets the isolation policy of a barbershop table (MT-04). */
    private const array TABLES = ['reservations', 'reservation_status_history'];

    /** The seven statuses of RE-04. */
    private const string STATUSES = "'temporary', 'confirmed', 'completed', 'no_show', 'cancelled_by_customer', 'cancelled_by_barbershop', 'expired'";

    /**
     * Create the reservations and the history of their statuses (RE-03, RE-04, RE-09, SV-03).
     * Every foreign key carries the barbershop, so a reservation can never point at the collaborator, service or customer
     * of another barbershop (MT-04). The two exclusions are the second barrier of RE-01 and RE-06 (ADR 0009); the btree_gist
     * extension comes from the migration of opening_hours.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('collaborator_id');
            $table->uuid('service_id');
            $table->uuid('customer_membership_id');
            $table->string('status', 30);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            // The end of the service plus the five minutes of cleaning (DI-01), stored because an index expression must be immutable.
            $table->timestampTz('blocked_until');
            // Copies of the service at the moment of booking (SV-03, CA-01).
            $table->integer('price');
            $table->smallInteger('duration_minutes');
            $table->smallInteger('cancellation_deadline_hours');
            $table->smallInteger('reschedule_count')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            $table->foreign(['collaborator_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('collaborators');
            // A service is never deleted (SV-04), so this key has no cascade either.
            $table->foreign(['service_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('services');
            $table->foreign(['customer_membership_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('memberships');
        });

        $statuses = self::STATUSES;
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_starts_at_check CHECK (starts_at < ends_at)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_blocked_until_check CHECK (ends_at <= blocked_until)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_price_check CHECK (price > 0)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_duration_minutes_check CHECK (duration_minutes > 0)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_cancellation_deadline_hours_check CHECK (cancellation_deadline_hours >= 0)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_reschedule_count_check CHECK (reschedule_count BETWEEN 0 AND 2)');

        // A collaborator has no two temporary or confirmed reservations that share a minute, cleaning included (RE-01).
        // The range is [start, end): a reservation that starts when the previous one is unblocked does not overlap it.
        DB::statement(<<<'SQL'
            ALTER TABLE reservations ADD CONSTRAINT reservations_no_overlap_excl EXCLUDE USING gist (
                barbershop_id WITH =,
                collaborator_id WITH =,
                tstzrange(starts_at, blocked_until) WITH &&
            ) WHERE (status IN ('temporary', 'confirmed'))
            SQL);

        // A customer has no two confirmed reservations that share a minute of the service itself (RE-06).
        DB::statement(<<<'SQL'
            ALTER TABLE reservations ADD CONSTRAINT reservations_customer_no_overlap_excl EXCLUDE USING gist (
                barbershop_id WITH =,
                customer_membership_id WITH =,
                tstzrange(starts_at, ends_at) WITH &&
            ) WHERE (status = 'confirmed')
            SQL);

        Schema::create('reservation_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('reservation_id');
            // Null when the reservation came to be.
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            // Null when the system made the change (a webhook, the expiry task).
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('changed_at');

            $table->unique(['id', 'barbershop_id']);
            $table->foreign(['reservation_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('reservations')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE reservation_status_history ADD CONSTRAINT reservation_status_history_from_status_check CHECK (from_status IS NULL OR from_status IN ({$statuses}))");
        DB::statement("ALTER TABLE reservation_status_history ADD CONSTRAINT reservation_status_history_to_status_check CHECK (to_status IN ({$statuses}))");

        foreach (self::TABLES as $name) {
            DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$name}_barbershop_isolation ON {$name}
                    USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                    WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                SQL);
        }
    }

    /** Remove the policies and the security of the tables and drop them, children first. The extension stays: it belongs to opening_hours. */
    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            DB::statement("DROP POLICY IF EXISTS {$name}_barbershop_isolation ON {$name}");
            DB::statement("ALTER TABLE {$name} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} DISABLE ROW LEVEL SECURITY");

            Schema::dropIfExists($name);
        }
    }
};
