<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

// #5734: the driver directory sorts by stored totals instead of summing every driver's jobs
// per request (kept up to date by App\Achievements\Progress, like `level`); the bell reads
// its rows by an index.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('earned_km', 12, 1)->default(0);
            $table->unsignedInteger('earned_jobs')->default(0);
            $table->index(['earned_km', 'name'], 'users_earned_km_name_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_notifiable_created_index');
        });

        // Backfill for the jobs synced so far, without ringing anyone's bell.
        Artisan::call('hub:progress');
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_created_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_earned_km_name_index');
            $table->dropColumn(['earned_km', 'earned_jobs']);
        });
    }
};
