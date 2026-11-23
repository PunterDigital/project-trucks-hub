<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A company event can link to its TruckersMP event and show TruckersMP's route image (#5228).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_events', function (Blueprint $table) {
            $table->unsignedInteger('truckersmp_event_id')->nullable()->after('route_notes');
            $table->string('route_image_url', 500)->nullable()->after('truckersmp_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_events', function (Blueprint $table) {
            $table->dropColumn(['truckersmp_event_id', 'route_image_url']);
        });
    }
};
