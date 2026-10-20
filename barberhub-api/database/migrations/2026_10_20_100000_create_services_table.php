<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the services of a barbershop, with their duration and price ranges and the isolation policy (SV-01, SV-02, MT-04). */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->string('name', 120);
            $table->smallInteger('duration_minutes');
            $table->integer('price');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
        });

        DB::statement('ALTER TABLE services ADD CONSTRAINT services_duration_minutes_check CHECK (duration_minutes BETWEEN 10 AND 180 AND duration_minutes % 5 = 0)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_price_check CHECK (price > 0)');

        DB::statement('ALTER TABLE services ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE services FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY services_barbershop_isolation ON services
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security of the table, then drop it. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS services_barbershop_isolation ON services');
        DB::statement('ALTER TABLE services NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE services DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('services');
    }
};
