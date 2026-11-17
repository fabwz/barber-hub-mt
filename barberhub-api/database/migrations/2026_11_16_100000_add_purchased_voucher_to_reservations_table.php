<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let a reservation point at the voucher whose session it uses (RF-28, BO-04). It is optional: a reservation paid with a deposit
     * or with no payment has none. The foreign key carries the barbershop, so a reservation can only use a voucher of its own
     * barbershop (MT-04, BO-07), and there is no cascade: a voucher with reservations is never deleted. Cancelling and the absence read
     * the column to know which voucher gets its session back or keeps it.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE reservations ADD COLUMN purchased_voucher_id uuid NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE reservations ADD CONSTRAINT reservations_purchased_voucher_id_barbershop_id_foreign
                FOREIGN KEY (purchased_voucher_id, barbershop_id) REFERENCES purchased_vouchers (id, barbershop_id)
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX reservations_purchased_voucher_idx ON reservations (barbershop_id, purchased_voucher_id)
                WHERE purchased_voucher_id IS NOT NULL
            SQL);
    }

    /** Drop the index, the foreign key and the column. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reservations_purchased_voucher_idx');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_purchased_voucher_id_barbershop_id_foreign');
        DB::statement('ALTER TABLE reservations DROP COLUMN IF EXISTS purchased_voucher_id');
    }
};
