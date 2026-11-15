<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the technical record of the sensitive actions: who changed what, when, from which value to which (SE-04).
     *
     * The record only grows: the application role is granted no UPDATE or DELETE on it, and a trigger refuses both for any
     * other role too, the owner of the table included. The application role keeps SELECT only because the isolation tests of the
     * barbershops read every table; no route or screen reads the record. `changes` is a JSON object and never holds passwords,
     * tokens or contact details: the application keeps only the fields each action allows.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->string('action', 60);
            $table->string('subject_type', 60);
            $table->uuid('subject_id');
            $table->jsonb('changes');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            // The record of a barbershop in order, and of one subject.
            $table->index(['barbershop_id', 'created_at'], 'audit_log_barbershop_idx');
            $table->index(['barbershop_id', 'subject_type', 'subject_id'], 'audit_log_subject_idx');
        });

        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_action_check CHECK (btrim(action) <> '')");
        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_subject_type_check CHECK (btrim(subject_type) <> '')");
        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_changes_check CHECK (jsonb_typeof(changes) = 'object')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_audit_log_append_only() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = pg_catalog
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'audit_log only grows: an entry is never changed or deleted'
                    USING ERRCODE = 'restrict_violation';
            END
            $fn$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_audit_log_append_only BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION fn_audit_log_append_only()
            SQL);
        $role = $this->applicationRole();
        DB::statement("REVOKE UPDATE, DELETE ON audit_log FROM {$role}");

        DB::statement('ALTER TABLE audit_log ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_log FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY audit_log_barbershop_isolation ON audit_log
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy, the security, the trigger and its function, and drop the table with its constraints and indexes. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS audit_log_barbershop_isolation ON audit_log');
        DB::statement('ALTER TABLE audit_log NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_log DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP TRIGGER IF EXISTS trg_audit_log_append_only ON audit_log');
        DB::statement('DROP FUNCTION IF EXISTS fn_audit_log_append_only()');

        Schema::dropIfExists('audit_log');
    }

    /** The application role comes from the regular connection, even when the migration runs through the administrative one. */
    private function applicationRole(): string
    {
        $name = config('database.connections.pgsql.username');

        return '"'.str_replace('"', '""', is_string($name) ? $name : '').'"';
    }
};
