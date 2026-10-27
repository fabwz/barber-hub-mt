<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the payment policy of each barbershop: how it charges, the percentage of the deposit and the free-cancellation
     * deadline in hours (PA-01, CA-01, MT-04). A barbershop that charges needs a gateway (PA-02).
     */
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->string('payment_mode', 10)->default('none');
            $table->smallInteger('deposit_percentage')->nullable();
            $table->smallInteger('cancellation_deadline_hours')->default(24);
        });

        DB::statement("alter table barbershops add constraint barbershops_payment_mode_check check (payment_mode in ('none', 'deposit', 'full'))");
        DB::statement('alter table barbershops add constraint barbershops_deposit_percentage_check check (deposit_percentage between 10 and 100)');
        DB::statement("alter table barbershops add constraint barbershops_deposit_percentage_mode_check check ((payment_mode = 'deposit') = (deposit_percentage is not null))");
        DB::statement('alter table barbershops add constraint barbershops_cancellation_deadline_check check (cancellation_deadline_hours between 0 and 720)');
        DB::statement("alter table barbershops add constraint barbershops_payment_gateway_required_check check (payment_mode = 'none' or payment_gateway is not null)");
    }

    /** Remove the payment policy with its checks. */
    public function down(): void
    {
        foreach (['payment_gateway_required', 'cancellation_deadline', 'deposit_percentage_mode', 'deposit_percentage', 'payment_mode'] as $check) {
            DB::statement("alter table barbershops drop constraint barbershops_{$check}_check");
        }

        Schema::table('barbershops', function (Blueprint $table): void {
            $table->dropColumn(['payment_mode', 'deposit_percentage', 'cancellation_deadline_hours']);
        });
    }
};
