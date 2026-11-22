<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly activity requirement (#5225): an optional minimum distance and/or number of
     * counted jobs per UTC month for every member (`App\Support\CompanyActivity`). Null on
     * both = no requirement. `activity_checked_month` ("YYYY-MM") is the last month whose
     * "who missed it" notice went out, so a second run of `hub:activity-check` sends nothing.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('activity_min_km')->nullable();
            $table->unsignedSmallInteger('activity_min_deliveries')->nullable();
            $table->string('activity_checked_month', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['activity_min_km', 'activity_min_deliveries', 'activity_checked_month']);
        });
    }
};
