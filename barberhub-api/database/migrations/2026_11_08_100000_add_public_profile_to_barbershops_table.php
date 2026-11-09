<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Columns that hold a link to a social network; each one must be an https link (RF-01). */
    private const array LINK_COLUMNS = ['whatsapp_url', 'instagram_url', 'facebook_url', 'tiktok_url'];

    /**
     * Add the optional public data of each barbershop: the address, the contact email of the business (not the one of the
     * owner's account) and the links to four social networks (RF-01, RF-02). The table has no row-level security: its rows
     * are the barbershops themselves, so it has no barbershop column to filter by.
     */
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->string('address', 200)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('whatsapp_url', 300)->nullable();
            $table->string('instagram_url', 300)->nullable();
            $table->string('facebook_url', 300)->nullable();
            $table->string('tiktok_url', 300)->nullable();
        });

        foreach (self::LINK_COLUMNS as $column) {
            DB::statement("alter table barbershops add constraint barbershops_{$column}_check check ({$column} like 'https://%')");
        }
    }

    /** Remove the public data with its checks. */
    public function down(): void
    {
        foreach (self::LINK_COLUMNS as $column) {
            DB::statement("alter table barbershops drop constraint barbershops_{$column}_check");
        }

        Schema::table('barbershops', function (Blueprint $table): void {
            $table->dropColumn(['address', 'contact_email', ...self::LINK_COLUMNS]);
        });
    }
};
