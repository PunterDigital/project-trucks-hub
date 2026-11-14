<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Hub's own anti-cheat result (#4642), next to the app's `flagged` / `flag_reason`.
     * Once set, a re-sync never clears it. `hub_held` marks a rate-limit hold (too many jobs
     * in an hour), which a moderator can review later.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('hub_flag_reason')->nullable()->after('flag_reason');
            $table->boolean('hub_held')->default(false)->after('hub_flag_reason');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['hub_flag_reason', 'hub_held']);
        });
    }
};
