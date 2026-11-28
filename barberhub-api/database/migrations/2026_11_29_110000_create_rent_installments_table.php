<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the monthly rent installments of a barbershop (RF-57, PL-05). Each one keeps the amount it was created with and the day it
     * falls due. There is no counter of pending months: they are counted from the overdue unpaid installments. The unique key
     * (barbershop_id, due_on) is what makes the daily generation idempotent. The table carries the barbershop and the isolation policy of
     * the tables of a barbershop.
     */
    public function up(): void
    {
        Schema::create('rent_installments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->integer('amount');
            $table->date('due_on');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            $table->unique(['barbershop_id', 'due_on'], 'rent_installments_due_unique');
        });

        DB::statement('ALTER TABLE rent_installments ADD CONSTRAINT rent_installments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE rent_installments ADD CONSTRAINT rent_installments_status_check CHECK (status IN ('pending', 'paid'))");
        DB::statement('ALTER TABLE rent_installments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE rent_installments FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY rent_installments_barbershop_isolation ON rent_installments
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security of the table, then drop it. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS rent_installments_barbershop_isolation ON rent_installments');
        DB::statement('ALTER TABLE rent_installments NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE rent_installments DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('rent_installments');
    }
};
