<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the tables of the queue with the database driver: the waiting jobs and the failed ones, which stay visible for the monitoring
     * once a job runs out of its attempts (RNF-09). They are the tables of the framework and belong to no barbershop; a job that works on
     * the data of one carries its barbershop in its payload and opens its transaction with it.
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /** Drop the tables of the queue. */
    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
