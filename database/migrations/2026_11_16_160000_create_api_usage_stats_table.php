<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hourly API traffic counters for the admin Data usage page (#4667): one row per UTC hour and
// endpoint, written by the `RecordApiUsage` middleware. No per-request rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage_stats', function (Blueprint $table) {
            $table->id();
            $table->dateTime('hour');
            $table->string('endpoint', 40);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedInteger('throttled')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('jobs_synced')->default(0);
            $table->unsignedInteger('jobs_skipped')->default(0);
            $table->unsignedInteger('jobs_flagged')->default(0);
            $table->unsignedInteger('jobs_rejected')->default(0);
            $table->timestamps();
            $table->unique(['hour', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_stats');
    }
};
