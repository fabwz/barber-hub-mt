<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the paths of the logo and the banner of a barbershop and of the image of a service (RF-46, MT-05, SV-07). They are
     * paths inside the folder of the barbershop, never a full address, so the host can change without touching the rows. The
     * policies of the tables do not change: the columns are on rows the policies already cover.
     */
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table): void {
            $table->string('logo_path', 255)->nullable();
            $table->string('banner_path', 255)->nullable();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->string('image_path', 255)->nullable();
        });
    }

    /** Remove the three columns. */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });

        Schema::table('barbershops', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'banner_path']);
        });
    }
};
