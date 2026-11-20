<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let an offer point at the reservation that answers it (RF-32). Accepting an offer with a voucher or with a payment books the offered space,
     * and the offer keeps that reservation: the payment that confirms it is what accepts an offer that was waiting for it, and, from then on, the
     * time is held by the reservation and no longer by the offer (DI-05).
     *
     * It is optional: an offer nobody accepted has none. The foreign key carries the barbershop, so an offer can only point at a reservation of
     * its own barbershop (MT-04), and it is deferred: the offer is written with the identifier the reservation will have right before the
     * reservation exists, in the same transaction, and the database checks it when that transaction ends.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE waitlist_offers ADD COLUMN reservation_id uuid NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE waitlist_offers ADD CONSTRAINT waitlist_offers_reservation_id_barbershop_id_foreign
                FOREIGN KEY (reservation_id, barbershop_id) REFERENCES reservations (id, barbershop_id)
                DEFERRABLE INITIALLY DEFERRED
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX waitlist_offers_reservation_idx ON waitlist_offers (barbershop_id, reservation_id)
                WHERE reservation_id IS NOT NULL
            SQL);
    }

    /** Drop the index, the foreign key and the column. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS waitlist_offers_reservation_idx');
        DB::statement('ALTER TABLE waitlist_offers DROP CONSTRAINT IF EXISTS waitlist_offers_reservation_id_barbershop_id_foreign');
        DB::statement('ALTER TABLE waitlist_offers DROP COLUMN IF EXISTS reservation_id');
    }
};
