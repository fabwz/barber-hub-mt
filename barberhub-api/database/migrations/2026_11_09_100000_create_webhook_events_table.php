<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The notices of the gateways that were applied, once each (PA-05), and the function that tells which barbershop a payment
     * belongs to. The table has no barbershop_id and no policy: the notice arrives without a session (docs/database.md). The
     * unique key (gateway, event_id) is what makes a repeated notice a no-op, and the row is written in the same transaction as
     * its effect, so a notice that failed halfway is not recorded and the provider can retry it.
     *
     * The function is the only way to find the barbershop of a payment from what the provider sends. The payments table
     * forces its policy even on its owner, so the function sets the barbershop of each one in turn (and restores the previous
     * value) instead of bypassing the policy. It only reads, fixes its search_path, answers two identifiers and is
     * executable only by the application role.
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('gateway', 10);
            $table->string('event_id', 255);
            $table->string('event_type', 100);
            // No foreign key: a payment is never deleted, and the migrations that roll back the tables below this one must not be blocked by it.
            $table->uuid('payment_id');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->useCurrent();

            $table->unique(['gateway', 'event_id']);
        });

        DB::statement("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_gateway_check CHECK (gateway IN ('onvo', 'paypal'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION locate_payment_of_notice(p_provider varchar, p_reference varchar)
            RETURNS TABLE (barbershop_id uuid, payment_id uuid)
            LANGUAGE plpgsql
            VOLATILE
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_previous text := coalesce(current_setting('app.barbershop_id', true), '');
                v_barbershop uuid;
                v_payment uuid;
            BEGIN
                FOR v_barbershop IN SELECT b.id FROM public.barbershops b LOOP
                    PERFORM set_config('app.barbershop_id', v_barbershop::text, true);

                    SELECT p.id INTO v_payment
                    FROM public.payments p
                    WHERE p.barbershop_id = v_barbershop AND p.provider = p_provider AND p.provider_payment_id = p_reference;

                    IF FOUND THEN
                        PERFORM set_config('app.barbershop_id', v_previous, true);
                        RETURN QUERY SELECT v_barbershop, v_payment;
                        RETURN;
                    END IF;
                END LOOP;

                PERFORM set_config('app.barbershop_id', v_previous, true);
            END
            $fn$
            SQL);

        $role = $this->applicationRole();
        DB::statement('REVOKE ALL ON FUNCTION locate_payment_of_notice(varchar, varchar) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION locate_payment_of_notice(varchar, varchar) TO {$role}");
    }

    /** Drop the function and the table. */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS locate_payment_of_notice(varchar, varchar)');
        Schema::dropIfExists('webhook_events');
    }

    /** The application role comes from the regular connection, even when the migration runs through the administrative one. */
    private function applicationRole(): string
    {
        $name = config('database.connections.pgsql.username');

        return '"'.str_replace('"', '""', is_string($name) ? $name : '').'"';
    }
};
