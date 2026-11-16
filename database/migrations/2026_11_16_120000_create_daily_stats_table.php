<?php

use App\Admin\DailyStats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Daily rollups for the admin dashboard (#4665), one row per UTC day and metric.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('metric', 40);
            $table->decimal('value', 16, 2)->default(0);
            $table->timestamps();
            $table->unique(['date', 'metric'], 'daily_stats_date_metric_unique');
        });

        // The rollups and the live "today" numbers count by these timestamps.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->index(['created_at', 'user_id'], 'deliveries_created_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('created_at', 'users_created_idx');
        });

        // Backfill every closed day since the first account.
        DailyStats::fill();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_created_idx');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex('deliveries_created_idx');
        });

        Schema::dropIfExists('daily_stats');
    }
};
