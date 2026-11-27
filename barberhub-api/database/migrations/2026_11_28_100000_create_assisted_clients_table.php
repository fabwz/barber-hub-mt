<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the people the owner registers for an assisted reservation, who have no account (RF-12, CL-06). The table carries the
     * barbershop and the isolation policy of the tables of a barbershop, so an assisted client exists only inside the barbershop that
     * created them (MT-04). The email is optional; without it the client receives no notices. Nothing here is unique: two people may
     * share a phone, and the owner chooses among the results of a search.
     */
    public function up(): void
    {
        Schema::create('assisted_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 30);
            $table->string('email', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
            // The search by name or phone of a barbershop.
            $table->index(['barbershop_id', 'name'], 'assisted_clients_name_idx');
        });

        DB::statement("ALTER TABLE assisted_clients ADD CONSTRAINT assisted_clients_name_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE assisted_clients ADD CONSTRAINT assisted_clients_phone_check CHECK (btrim(phone) <> '')");
        DB::statement("ALTER TABLE assisted_clients ADD CONSTRAINT assisted_clients_email_check CHECK (email IS NULL OR btrim(email) <> '')");
        DB::statement('ALTER TABLE assisted_clients ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE assisted_clients FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY assisted_clients_barbershop_isolation ON assisted_clients
                USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
            SQL);
    }

    /** Remove the policy and the security of the table, then drop it. */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS assisted_clients_barbershop_isolation ON assisted_clients');
        DB::statement('ALTER TABLE assisted_clients NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE assisted_clients DISABLE ROW LEVEL SECURITY');

        Schema::dropIfExists('assisted_clients');
    }
};
