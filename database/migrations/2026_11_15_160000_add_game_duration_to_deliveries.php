<?php

use App\Sync\JobChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-game minutes between pickup and delivery (#4771). The game's odometer counts game
     * kilometres, so the average speed is only a truck speed per in-game hour. Apps before 0.11
     * don't send it (null).
     *
     * Every recorded job synced so far was flagged "Average speed over 150 km/h", because that
     * check divided game kilometres by real time. Those jobs are checked again with today's
     * rules (the real-time limit for jobs without a game time). Staff decisions stay as they
     * are, and so does any job that still fails.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->unsignedInteger('game_duration_min')->nullable()->after('duration_sec');
        });

        JobChecks::recheckSpeedFlags();
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('game_duration_min');
        });
    }
};
