<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live map (#4687): where a driver's truck is (SCS world metres, from ProjectTrucks' live
     * status) and whether they want to be on the map at all.
     */
    public function up(): void
    {
        Schema::table('live_statuses', function (Blueprint $table) {
            $table->double('pos_x')->nullable();
            $table->double('pos_z')->nullable();
            $table->float('heading_deg')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_on_map')->default(true)->after('profile_private');
        });
    }

    public function down(): void
    {
        Schema::table('live_statuses', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_z', 'heading_deg']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_on_map');
        });
    }
};
