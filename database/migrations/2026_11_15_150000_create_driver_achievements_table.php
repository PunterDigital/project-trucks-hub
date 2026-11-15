<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

// Levels and achievements (#4645). `users.level` is kept up to date by App\Achievements\Progress.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->default(1);
        });

        Schema::create('driver_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('achievement', 40);
            $table->timestamp('unlocked_at');
            $table->timestamps();
            $table->unique(['user_id', 'achievement']);
        });

        // Backfill for the jobs synced so far, without ringing anyone's bell.
        Artisan::call('hub:progress');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_achievements');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
