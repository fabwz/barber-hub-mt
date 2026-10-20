<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the closures of a barbershop, one per date, with the isolation policy (HO-01, MT-04). */
    public function up(): void
    {
        Schema::create('closures', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->date('closed_on');
            $table->string('reason', 200)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['barbershop_id', 'closed_on']);
            $table->unique(['id', 'barbershop_id']);
        });

        DB::statement('ALTER TABLE closures ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE closures FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY closures_barbershop_isolation ON closures
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security of the table, then drop it. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS closures_barbershop_isolation ON closures');
        DB::statement('ALTER TABLE closures NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE closures DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('closures');
    }
};
