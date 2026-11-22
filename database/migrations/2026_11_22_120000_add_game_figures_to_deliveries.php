<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the game itself reported for a job (#5227), sent by ProjectTrucks 0.12+ with the
 * scs-telemetry plugin. All null for older apps and older jobs. `income` stays the offered
 * (declared) income; totals use `game_revenue` when there is one (Delivery::INCOME_SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->bigInteger('game_revenue')->nullable()->after('income');
            $table->integer('earned_xp')->nullable()->after('game_revenue');
            $table->float('cargo_damage')->nullable()->after('earned_xp');
            $table->bigInteger('costs')->nullable()->after('cargo_damage');
            $table->bigInteger('cancel_penalty')->nullable()->after('costs');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['game_revenue', 'earned_xp', 'cargo_damage', 'costs', 'cancel_penalty']);
        });
    }
};
