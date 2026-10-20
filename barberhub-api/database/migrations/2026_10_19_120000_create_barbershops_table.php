<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the tenants table; the public link, the logo and the payment columns come with their issues. */
    public function up(): void
    {
        Schema::create('barbershops', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('name', 120);
            $table->string('city', 80);
            $table->string('phone', 30);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    /** Drop the tenants table. */
    public function down(): void
    {
        Schema::dropIfExists('barbershops');
    }
};
