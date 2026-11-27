<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables of the module, parents first. Each one gets the isolation policy of a barbershop table (MT-04). */
    private const array TABLES = ['invitations', 'invitation_services'];

    /**
     * Create the invitations of collaborators and the services each one offers (RF-06, UP-04), and the function that tells which barbershop
     * the link of an invitation belongs to. Only the SHA-256 hash of the token is stored, never the token. A pending invitation is one with
     * no `used_at` and an `expires_at` still to come; a cancelled one is deleted. A barbershop has at most one pending invitation per
     * email, so inviting again replaces it.
     *
     * The accepting of an invitation has no session, so nothing says yet which barbershop it is about. The function is the only way to find
     * it from the hash of the link, exactly as `locate_payment_of_notice` does for the notices of the gateways: the table forces its policy
     * even on its owner, so the function sets the barbershop of each one in turn (and restores the previous value) instead of bypassing the
     * policy. It only reads, fixes its search_path, answers one identifier and is executable only by the application role.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->string('email', 255);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->uuid('invited_by_membership_id');
            $table->timestampTz('created_at')->useCurrent();

            // The second key lets the services of the invitation reference it with its barbershop.
            $table->unique(['id', 'barbershop_id']);
            $table->foreign(['invited_by_membership_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('memberships')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_token_hash_check CHECK (token_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_email_check CHECK (email = lower(email))');
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_expires_at_check CHECK (expires_at > created_at)');
        DB::statement('CREATE UNIQUE INDEX invitations_one_pending_per_email_idx ON invitations (barbershop_id, email) WHERE used_at IS NULL');

        Schema::create('invitation_services', function (Blueprint $table): void {
            // The schema convention asks every table for a uuid "id"; the pair below is what identifies the row for the business.
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('invitation_id');
            $table->uuid('service_id');

            $table->unique(['invitation_id', 'service_id']);
            $table->foreign(['invitation_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('invitations')->cascadeOnDelete();
            // A service is never deleted (SV-04), so this key has no cascade.
            $table->foreign(['service_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('services');
        });

        foreach (self::TABLES as $name) {
            DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$name}_barbershop_isolation ON {$name}
                    USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                    WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                SQL);
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION locate_invitation_of_link(p_token_hash varchar)
            RETURNS uuid
            LANGUAGE plpgsql
            VOLATILE
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_previous text := coalesce(current_setting('app.barbershop_id', true), '');
                v_barbershop uuid;
            BEGIN
                FOR v_barbershop IN SELECT b.id FROM public.barbershops b LOOP
                    PERFORM set_config('app.barbershop_id', v_barbershop::text, true);

                    IF EXISTS (SELECT 1 FROM public.invitations i WHERE i.barbershop_id = v_barbershop AND i.token_hash = p_token_hash::char(64)) THEN
                        PERFORM set_config('app.barbershop_id', v_previous, true);
                        RETURN v_barbershop;
                    END IF;
                END LOOP;

                PERFORM set_config('app.barbershop_id', v_previous, true);
                RETURN NULL;
            END
            $fn$
            SQL);

        $role = $this->applicationRole();
        DB::statement('REVOKE ALL ON FUNCTION locate_invitation_of_link(varchar) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION locate_invitation_of_link(varchar) TO {$role}");
    }

    /** Drop the function, remove the policies and the security of the tables and drop them, children first. */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS locate_invitation_of_link(varchar)');

        foreach (array_reverse(self::TABLES) as $name) {
            DB::statement("DROP POLICY IF EXISTS {$name}_barbershop_isolation ON {$name}");
            DB::statement("ALTER TABLE {$name} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} DISABLE ROW LEVEL SECURITY");

            Schema::dropIfExists($name);
        }
    }

    /** The application role comes from the regular connection, even when the migration runs through the administrative one. */
    private function applicationRole(): string
    {
        $name = config('database.connections.pgsql.username');

        return '"'.str_replace('"', '""', is_string($name) ? $name : '').'"';
    }
};
