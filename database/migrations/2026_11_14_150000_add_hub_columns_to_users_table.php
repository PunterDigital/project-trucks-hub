<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique();
            $table->string('steam_id', 32)->nullable()->unique();
            $table->string('avatar_url')->nullable();
            $table->boolean('profile_private')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['steam_id']);
            $table->dropColumn(['username', 'steam_id', 'avatar_url', 'profile_private']);
        });
    }
};
