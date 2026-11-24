<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the reminders of a reservation, one per kind (24 h and 2 h before), so that none is sent twice (NO-03). `scheduled_for` is the
     * instant the reminder is due: a reschedule rewrites it and clears `sent_at`, and the queued task that carries the old instant finds
     * no row with it and does nothing. The foreign key carries the barbershop, so a reminder can only point at a reservation of its own.
     */
    public function up(): void
    {
        Schema::create('reservation_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('reservation_id');
            $table->string('kind', 3);
            $table->timestampTz('scheduled_for');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            $table->unique(['barbershop_id', 'reservation_id', 'kind']);
            $table->foreign(['reservation_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('reservations')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE reservation_reminders ADD CONSTRAINT reservation_reminders_kind_check CHECK (kind IN ('24h', '2h'))");

        DB::statement('ALTER TABLE reservation_reminders ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE reservation_reminders FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY reservation_reminders_barbershop_isolation ON reservation_reminders
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security, and drop the table with its constraints. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS reservation_reminders_barbershop_isolation ON reservation_reminders');
        DB::statement('ALTER TABLE reservation_reminders NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE reservation_reminders DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('reservation_reminders');
    }
};
