<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a reservation belong to an assisted client instead of an account (RF-12, CL-06). The customer role becomes optional and a
     * check asks for exactly one of the two, so a reservation always has a customer and never two. Both foreign keys carry the
     * barbershop: a reservation can only point at an assisted client, and at the owner who registered it, of its own barbershop
     * (MT-04). The exclusion of RE-06 stays on the customer role; a null never equals another, so it ignores assisted reservations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE reservations ALTER COLUMN customer_membership_id DROP NOT NULL');
        DB::statement('ALTER TABLE reservations ADD COLUMN assisted_client_id uuid NULL');
        DB::statement('ALTER TABLE reservations ADD COLUMN created_by_membership_id uuid NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE reservations ADD CONSTRAINT reservations_assisted_client_id_barbershop_id_foreign
                FOREIGN KEY (assisted_client_id, barbershop_id) REFERENCES assisted_clients (id, barbershop_id)
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE reservations ADD CONSTRAINT reservations_created_by_membership_id_barbershop_id_foreign
                FOREIGN KEY (created_by_membership_id, barbershop_id) REFERENCES memberships (id, barbershop_id)
            SQL);
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_customer_check CHECK (num_nonnulls(customer_membership_id, assisted_client_id) = 1)');
        DB::statement(<<<'SQL'
            CREATE INDEX reservations_assisted_client_idx ON reservations (barbershop_id, assisted_client_id)
                WHERE assisted_client_id IS NOT NULL
            SQL);
    }

    /** Drop the index, the check, the foreign keys and the columns; the customer role becomes required again. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reservations_assisted_client_idx');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_customer_check');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_created_by_membership_id_barbershop_id_foreign');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_assisted_client_id_barbershop_id_foreign');

        // The reservations of assisted clients cannot keep a customer once the column is gone, so they go with it.
        if (Schema::hasColumn('reservations', 'assisted_client_id')) {
            DB::statement('DELETE FROM reservations WHERE assisted_client_id IS NOT NULL');
        }

        DB::statement('ALTER TABLE reservations DROP COLUMN IF EXISTS created_by_membership_id');
        DB::statement('ALTER TABLE reservations DROP COLUMN IF EXISTS assisted_client_id');
        DB::statement('ALTER TABLE reservations ALTER COLUMN customer_membership_id SET NOT NULL');
    }
};
