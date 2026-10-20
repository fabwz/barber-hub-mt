<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the weekly schedule of a barbershop, with the exclusion of overlapping slots and the isolation policy (HO-01, MT-04). */
    public function up(): void
    {
        // A trusted extension: the owner of the database installs it without being a superuser. It lets the EXCLUDE compare uuid and smallint columns with "=".
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // migrate:fresh drops the tables but not the types, so a type left by an earlier run is replaced.
        DB::statement('DROP TYPE IF EXISTS timerange');
        DB::statement('CREATE TYPE timerange AS RANGE (subtype = time)');

        Schema::create('opening_hours', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->smallInteger('day_of_week');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
        });

        DB::statement('ALTER TABLE opening_hours ADD CONSTRAINT opening_hours_day_of_week_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE opening_hours ADD CONSTRAINT opening_hours_opens_at_check CHECK (opens_at < closes_at)');
        // The range is [opens_at, closes_at): two slots that only touch (09:00-12:00 and 12:00-15:00) do not overlap.
        DB::statement(<<<'SQL'
            ALTER TABLE opening_hours ADD CONSTRAINT opening_hours_no_overlap_excl EXCLUDE USING gist (
                barbershop_id WITH =,
                day_of_week WITH =,
                timerange(opens_at, closes_at) WITH &&
            )
            SQL);

        DB::statement('ALTER TABLE opening_hours ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE opening_hours FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY opening_hours_barbershop_isolation ON opening_hours
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security of the table, drop it, and leave no type or extension behind. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS opening_hours_barbershop_isolation ON opening_hours');
        DB::statement('ALTER TABLE opening_hours NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE opening_hours DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('opening_hours');

        DB::statement('DROP TYPE IF EXISTS timerange');
        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
    }
};
