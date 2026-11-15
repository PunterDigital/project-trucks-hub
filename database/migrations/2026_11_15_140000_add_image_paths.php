<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Uploaded pictures (#4644): paths on the public disk, served by MediaController.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('avatar_url');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'banner_path']);
        });
    }
};
