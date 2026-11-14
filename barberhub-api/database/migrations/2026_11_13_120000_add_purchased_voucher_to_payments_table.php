<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let a payment belong to a purchased voucher instead of a reservation (RF-27, BO-02, PA-03). Until now every payment had a
     * reservation; now it has exactly one of the two, which a CHECK enforces whatever writes the row. The foreign key to the
     * voucher carries the barbershop, so a payment can never point at a voucher of another one, and the partial unique index gives
     * a voucher one payment pending or approved at a time: it is the idempotency of the buy button, as the one of the
     * reservation is for the pay button. The voucher itself is activated once by the unique purchase entry of its record.
     *
     * The function that locates the payment of a notice does not change: it looks the payment up by provider and reference, which a
     * voucher payment has as any other.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE payments ALTER COLUMN reservation_id DROP NOT NULL');
        DB::statement('ALTER TABLE payments ADD COLUMN purchased_voucher_id uuid NULL');
        // A voucher is never deleted while it has payments: the money trail stays.
        DB::statement(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT payments_purchased_voucher_id_barbershop_id_foreign
                FOREIGN KEY (purchased_voucher_id, barbershop_id) REFERENCES purchased_vouchers (id, barbershop_id)
            SQL);
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_one_subject_check CHECK ((reservation_id IS NULL) <> (purchased_voucher_id IS NULL))');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_one_active_per_voucher_idx ON payments (barbershop_id, purchased_voucher_id)
                WHERE status IN ('pending', 'approved') AND purchased_voucher_id IS NOT NULL
            SQL);
    }

    /** Drop what was added and make the reservation mandatory again; it refuses while a payment of a voucher exists, so no money trail is erased. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_one_active_per_voucher_idx');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_one_subject_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_purchased_voucher_id_barbershop_id_foreign');
        DB::statement('ALTER TABLE payments DROP COLUMN IF EXISTS purchased_voucher_id');
        DB::statement('ALTER TABLE payments ALTER COLUMN reservation_id SET NOT NULL');
    }
};
