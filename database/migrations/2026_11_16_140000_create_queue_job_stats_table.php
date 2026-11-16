<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hourly counters of queued jobs for the admin Queue page (#4666), written by the queue
// worker's JobProcessed / JobFailed events (App\Admin\QueueStats).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_job_stats', function (Blueprint $table) {
            $table->id();
            $table->dateTime('hour');
            $table->string('job_class', 190);
            $table->string('queue', 60);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->timestamps();
            $table->unique(['hour', 'job_class', 'queue'], 'queue_stats_hour_class_queue_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_job_stats');
    }
};
