<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the vouchers a barbershop sells and the services each one covers (BO-01, MT-04). The covered services point at the
     * voucher and at the service through foreign keys that carry the barbershop, so a voucher can never cover a service of
     * another barbershop. The ranges of the business are CHECK constraints, so they hold even for a row that does not come
     * through the domain.
     */
    public function up(): void
    {
        Schema::create('voucher_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->string('name', 120);
            $table->smallInteger('sessions_count');
            // Colones, whole, VAT included.
            $table->integer('price');
            $table->smallInteger('validity_days');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['id', 'barbershop_id']);
        });

        DB::statement("ALTER TABLE voucher_definitions ADD CONSTRAINT voucher_definitions_name_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE voucher_definitions ADD CONSTRAINT voucher_definitions_sessions_count_check CHECK (sessions_count > 0)');
        DB::statement('ALTER TABLE voucher_definitions ADD CONSTRAINT voucher_definitions_price_check CHECK (price > 0)');
        DB::statement('ALTER TABLE voucher_definitions ADD CONSTRAINT voucher_definitions_validity_days_check CHECK (validity_days > 0)');

        Schema::create('voucher_definition_services', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('barbershop_id')->constrained('barbershops')->cascadeOnDelete();
            $table->uuid('voucher_definition_id');
            $table->uuid('service_id');
            $table->timestampTz('created_at')->useCurrent();

            // A service is covered once by a voucher.
            $table->unique(['barbershop_id', 'voucher_definition_id', 'service_id'], 'voucher_definition_services_unique');
            // The definition goes with its services; a service that is covered by a voucher is never deleted (SV-04).
            $table->foreign(['voucher_definition_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('voucher_definitions')->cascadeOnDelete();
            $table->foreign(['service_id', 'barbershop_id'])->references(['id', 'barbershop_id'])->on('services');
            // Which vouchers cover a service (BO-06).
            $table->index(['barbershop_id', 'service_id']);
        });

        foreach (['voucher_definitions', 'voucher_definition_services'] as $name) {
            DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$name}_barbershop_isolation ON {$name}
                    USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                    WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
                SQL);
        }
    }

    /** Remove the policies and the security of both tables and drop them with their constraints and indexes. */
    public function down(): void
    {
        foreach (['voucher_definition_services', 'voucher_definitions'] as $name) {
            DB::statement("DROP POLICY IF EXISTS {$name}_barbershop_isolation ON {$name}");
            DB::statement("ALTER TABLE {$name} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$name} DISABLE ROW LEVEL SECURITY");

            Schema::dropIfExists($name);
        }
    }
};
