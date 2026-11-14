<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the vouchers the customers bought and the record of their balances (BO-02, BO-03, BO-09, MT-04).
     *
     * The foreign keys carry the barbershop, so a purchased voucher can only point at a definition and a customer of its own
     * barbershop, and an entry of the record only at a voucher and a reservation of it. The CHECK constraints make the balance
     * impossible to leave negative or above the sessions bought, whatever writes the row.
     *
     * The record only grows: the application role is granted no UPDATE or DELETE on it, and a trigger refuses both for any
     * other role too, the owner of the table included. Its partial unique indexes give the idempotency of the rules: a voucher
     * has one purchase entry, and a reservation sets one session aside and settles it once.
     */
    public function up(): void
    {
        Schema::create('purchased_vouchers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('customer_membership_id');
            $table->uuid('voucher_definition_id');
            // What was bought, copied from the definition: editing or withdrawing it later does not change this voucher.
            $table->string('name', 120);
            $table->integer('price');
            $table->smallInteger('sessions_count');
            $table->smallInteger('validity_days');
            $table->string('status', 10);
            // Null until the payment is confirmed; the validity counts from the activation (RF-27).
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->smallInteger('remaining_sessions')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            // A customer is never deleted while they have vouchers: the balance they paid for stays.
            $table->foreign(['customer_membership_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('memberships');
            $table->foreign(['voucher_definition_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('voucher_definitions');
            // The vouchers of a person, soonest to expire first (BO-08).
            $table->index(['barbershop_id', 'customer_membership_id', 'expires_at'], 'purchased_vouchers_customer_idx');
        });

        DB::statement("ALTER TABLE purchased_vouchers ADD CONSTRAINT purchased_vouchers_name_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE purchased_vouchers ADD CONSTRAINT purchased_vouchers_price_check CHECK (price > 0)');
        DB::statement('ALTER TABLE purchased_vouchers ADD CONSTRAINT purchased_vouchers_sessions_count_check CHECK (sessions_count > 0)');
        DB::statement('ALTER TABLE purchased_vouchers ADD CONSTRAINT purchased_vouchers_validity_days_check CHECK (validity_days > 0)');
        DB::statement("ALTER TABLE purchased_vouchers ADD CONSTRAINT purchased_vouchers_status_check CHECK (status IN ('pending', 'active'))");
        // BO-03: the balance is never negative, and never above the sessions that were bought.
        DB::statement('ALTER TABLE purchased_vouchers ADD CONSTRAINT purchased_vouchers_remaining_sessions_check CHECK (remaining_sessions >= 0)');
        DB::statement('ALTER TABLE purchased_vouchers ADD CONSTRAINT purchased_vouchers_remaining_within_total_check CHECK (remaining_sessions <= sessions_count)');
        // BO-02: a voucher that is not paid has no dates and no sessions; one that is paid has both dates, in order.
        DB::statement(<<<'SQL'
            ALTER TABLE purchased_vouchers ADD CONSTRAINT purchased_vouchers_activation_check CHECK (
                (status = 'pending' AND activated_at IS NULL AND expires_at IS NULL AND remaining_sessions = 0)
                OR (status = 'active' AND activated_at IS NOT NULL AND expires_at IS NOT NULL AND expires_at > activated_at)
            )
            SQL);

        // The vouchers about to expire with sessions left.
        DB::statement(<<<'SQL'
            CREATE INDEX purchased_vouchers_expiring_idx ON purchased_vouchers (barbershop_id, expires_at)
                WHERE status = 'active' AND remaining_sessions > 0
            SQL);

        Schema::create('voucher_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('purchased_voucher_id');
            // The reservation behind the entry; a purchase has none (BO-09).
            $table->uuid('reservation_id')->nullable();
            $table->string('type', 10);
            $table->smallInteger('sessions_delta');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            $table->foreign(['purchased_voucher_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('purchased_vouchers');
            // A reservation is never deleted while it has entries: the trail of the balance stays.
            $table->foreign(['reservation_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('reservations');
            // The record of a voucher, to add it up and to read it in order.
            $table->index(['barbershop_id', 'purchased_voucher_id', 'created_at'], 'voucher_movements_voucher_idx');
        });

        DB::statement("ALTER TABLE voucher_movements ADD CONSTRAINT voucher_movements_type_check CHECK (type IN ('purchase', 'hold', 'consume', 'release'))");
        // Each kind has its amount, and only the purchase has no reservation: the sum of the entries is the balance.
        DB::statement(<<<'SQL'
            ALTER TABLE voucher_movements ADD CONSTRAINT voucher_movements_shape_check CHECK (
                (type = 'purchase' AND sessions_delta > 0 AND reservation_id IS NULL)
                OR (type = 'hold' AND sessions_delta = -1 AND reservation_id IS NOT NULL)
                OR (type = 'release' AND sessions_delta = 1 AND reservation_id IS NOT NULL)
                OR (type = 'consume' AND sessions_delta = 0 AND reservation_id IS NOT NULL)
            )
            SQL);

        DB::statement('CREATE INDEX voucher_movements_reservation_idx ON voucher_movements (barbershop_id, reservation_id) WHERE reservation_id IS NOT NULL');
        // A voucher is activated once (BO-02, PA-05); a reservation sets one session aside once and settles it once (BO-04, BO-05).
        DB::statement("CREATE UNIQUE INDEX voucher_movements_one_purchase_idx ON voucher_movements (barbershop_id, purchased_voucher_id) WHERE type = 'purchase'");
        DB::statement("CREATE UNIQUE INDEX voucher_movements_one_hold_idx ON voucher_movements (barbershop_id, purchased_voucher_id, reservation_id) WHERE type = 'hold'");
        DB::statement("CREATE UNIQUE INDEX voucher_movements_one_settlement_idx ON voucher_movements (barbershop_id, purchased_voucher_id, reservation_id) WHERE type IN ('release', 'consume')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_voucher_movements_append_only() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = pg_catalog
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'voucher_movements only grows: an entry is never changed or deleted'
                    USING ERRCODE = 'restrict_violation';
            END
            $fn$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_voucher_movements_append_only BEFORE UPDATE OR DELETE ON voucher_movements
                FOR EACH ROW EXECUTE FUNCTION fn_voucher_movements_append_only()
            SQL);
        $role = $this->applicationRole();
        DB::statement("REVOKE UPDATE, DELETE ON voucher_movements FROM {$role}");

        foreach (['purchased_vouchers', 'voucher_movements'] as $name) {
            DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$name}_barbershop_isolation ON {$name}
                    USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                    WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                SQL);
        }
    }

    /** Remove the policies, the security, the trigger and its function, and drop both tables with their constraints and indexes. */
    public function down(): void
    {
        foreach (['voucher_movements', 'purchased_vouchers'] as $name) {
            DB::statement("DROP POLICY IF EXISTS {$name}_barbershop_isolation ON {$name}");
            DB::statement("ALTER TABLE {$name} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_voucher_movements_append_only ON voucher_movements');
        DB::statement('DROP FUNCTION IF EXISTS fn_voucher_movements_append_only()');

        Schema::dropIfExists('voucher_movements');
        Schema::dropIfExists('purchased_vouchers');
    }

    /** The application role comes from the regular connection, even when the migration runs through the administrative one. */
    private function applicationRole(): string
    {
        $name = config('database.connections.pgsql.username');

        return '"'.str_replace('"', '""', is_string($name) ? $name : '').'"';
    }
};
