<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the absences that block the agenda of a collaborator, with the isolation policy of a barbershop table (RF-07, CO-06,
     * MT-04). The foreign key carries the barbershop, so an absence can never point to a collaborator of another one. Two absences
     * of the same collaborator may overlap: blocking a time twice changes nothing, so no exclusion is asked of the table.
     */
    public function up(): void
    {
        Schema::create('collaborator_absences', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('collaborator_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 200)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            $table->foreign(['collaborator_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('collaborators')->cascadeOnDelete();
            // The availability reads the absences of some collaborators that overlap a day.
            $table->index(['barbershop_id', 'collaborator_id', 'starts_at', 'ends_at'], 'collaborator_absences_lookup_idx');
        });

        DB::statement('ALTER TABLE collaborator_absences ADD CONSTRAINT collaborator_absences_range_check CHECK (starts_at < ends_at)');
        DB::statement('ALTER TABLE collaborator_absences ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE collaborator_absences FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY collaborator_absences_barbershop_isolation ON collaborator_absences
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security of the table, then drop it. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS collaborator_absences_barbershop_isolation ON collaborator_absences');
        DB::statement('ALTER TABLE collaborator_absences NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE collaborator_absences DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('collaborator_absences');
    }
};
