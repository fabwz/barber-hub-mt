<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add the payment gateway of each barbershop: null until it picks ONVO Pay or PayPal (PA-02, MT-04). */
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->string('payment_gateway', 10)->nullable();
        });

        DB::statement("alter table barbershops add constraint barbershops_payment_gateway_check check (payment_gateway in ('onvo', 'paypal'))");
    }

    /** Remove the payment gateway with its check. */
    public function down(): void
    {
        DB::statement('alter table barbershops drop constraint barbershops_payment_gateway_check');

        Schema::table('barbershops', function (Blueprint $table): void {
            $table->dropColumn('payment_gateway');
        });
    }
};
