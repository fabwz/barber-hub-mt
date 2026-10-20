<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables of the module, parents first. Each one gets the isolation policy of a barbershop table (MT-04). */
    private const array TABLES = ['collaborators', 'collaborator_services', 'collaborator_shifts', 'collaborator_breaks'];

    /**
     * Create the collaborator profiles, the services they offer and their weekly shifts and breaks (CO-01, HO-02).
     * Every foreign key carries the barbershop, so a relation can never cross barbershops (MT-04).
     */
    public function up(): void
    {
        Schema::create('collaborators', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // A person has one profile per barbershop; the second key lets other tables reference the profile with its barbershop.
            $table->unique(['barbershop_id', 'user_id']);
            $table->unique(['id', 'barbershop_id']);
        });

        Schema::create('collaborator_services', function (Blueprint $table): void {
            // The schema convention asks every table for a uuid "id"; the pair below is what identifies the row for the business.
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('collaborator_id');
            $table->uuid('service_id');

            $table->unique(['collaborator_id', 'service_id']);
            $table->foreign(['collaborator_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('collaborators')->cascadeOnDelete();
            // A service is never deleted (SV-04), so this key has no cascade.
            $table->foreign(['service_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('services');
        });

        foreach (['collaborator_shifts', 'collaborator_breaks'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
                $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
                $table->uuid('collaborator_id');
                $table->smallInteger('day_of_week');
                $table->time('starts_at');
                $table->time('ends_at');
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();

                $table->unique(['id', 'barbershop_id']);
                $table->foreign(['collaborator_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('collaborators')->cascadeOnDelete();
            });

            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_day_of_week_check CHECK (day_of_week BETWEEN 1 AND 7)");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_starts_at_check CHECK (starts_at < ends_at)");
            // The range is [starts_at, ends_at): two entries that only touch (09:00-12:00 and 12:00-15:00) do not overlap.
            DB::statement(<<<SQL
                ALTER TABLE {$name} ADD CONSTRAINT {$name}_no_overlap_excl EXCLUDE USING gist (
                    barbershop_id WITH =,
                    collaborator_id WITH =,
                    day_of_week WITH =,
                    timerange(starts_at, ends_at) WITH &&
                )
                SQL);
        }

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

    /** Remove the policies and the security of the tables and drop them, children first. */
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
