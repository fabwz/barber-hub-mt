<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keep a platform operator out of every barbershop (PL-01, PL-02) in the database too: a role cannot be given to an account marked as
     * operator, whichever path tries it (the registration of a barbershop, an accepted invitation, the first booking of a customer, or a
     * statement written by hand). The use cases refuse it first with a clear answer; this is the barrier behind them, so a missed check or a
     * race still cannot leave an operator with a membership. "users" has no row security, so the function reads the mark as any role does.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_memberships_exclude_platform_operators() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND is_platform_operator) THEN
                    RAISE EXCEPTION 'a platform operator cannot hold a role in a barbershop'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END
            $fn$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_memberships_exclude_platform_operators BEFORE INSERT OR UPDATE OF user_id ON memberships
                FOR EACH ROW EXECUTE FUNCTION fn_memberships_exclude_platform_operators()
            SQL);
    }

    /** Remove the trigger and its function. */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_memberships_exclude_platform_operators ON memberships');
        DB::statement('DROP FUNCTION IF EXISTS fn_memberships_exclude_platform_operators()');
    }
};
