<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the payments (PA-02, PA-06, MT-04). The foreign key to the reservation carries the barbershop, so a payment can
     * never point at a reservation of another one. The partial unique index lets a reservation have one payment pending or
     * approved at a time: it is the idempotency of the pay button. The identifier of the provider is unique per provider.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('reservation_id');
            $table->string('provider', 10);
            $table->string('method', 20);
            $table->string('status', 10);
            // Colones, whole and positive: what the customer pays (PA-06).
            $table->integer('amount');
            // What the provider charged in its own currency and unit, for traceability; both are null until it creates the charge.
            $table->char('provider_currency', 3)->nullable();
            $table->integer('provider_amount')->nullable();
            // Null until the charge exists in the provider.
            $table->string('provider_payment_id', 100)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            // A reservation is never deleted while it has payments: the money trail stays.
            $table->foreign(['reservation_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('reservations');
            $table->unique(['provider', 'provider_payment_id']);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_check CHECK (provider IN ('onvo', 'paypal'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('card', 'sinpe_movil'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'failed', 'refunded'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_provider_amount_check CHECK (provider_amount > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_provider_currency_amount_check CHECK ((provider_currency IS NULL) = (provider_amount IS NULL))');
        // A payment that was approved or refunded was charged in the provider, so it has its identifier there.
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_payment_id_check CHECK (status NOT IN ('approved', 'refunded') OR provider_payment_id IS NOT NULL)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_failure_reason_check CHECK (failure_reason IS NULL OR status IN ('rejected', 'failed'))");

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_one_active_per_reservation_idx ON payments (barbershop_id, reservation_id)
                WHERE status IN ('pending', 'approved')
            SQL);

        DB::statement('ALTER TABLE payments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payments FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY payments_barbershop_isolation ON payments
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security of the table and drop it with its constraints and indexes. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS payments_barbershop_isolation ON payments');
        DB::statement('ALTER TABLE payments NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE payments DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('payments');
    }
};
