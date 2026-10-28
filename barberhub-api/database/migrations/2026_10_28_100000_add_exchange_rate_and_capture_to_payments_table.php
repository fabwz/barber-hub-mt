<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the exchange rate a provider that charges in another currency used and the identifier of the capture that settled
     * the charge. Both are only for traceability and are null for the rest. The rate is an integer, as every number of the
     * schema (RD-06): ten-thousandths of a colon per unit of the currency of the provider, so 500 colones per dollar is 5000000.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedInteger('provider_exchange_rate')->nullable();
            $table->string('provider_capture_id', 100)->nullable();
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_provider_exchange_rate_check CHECK (provider_exchange_rate IS NULL OR (provider_amount IS NOT NULL AND provider_exchange_rate > 0))');
    }

    /** Remove the columns and the constraint. */
    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_provider_exchange_rate_check');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['provider_exchange_rate', 'provider_capture_id']);
        });
    }
};
