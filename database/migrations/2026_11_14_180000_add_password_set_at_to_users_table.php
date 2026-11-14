<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Steam sign-ups get a random password nobody knows, so screens that ask for the current
     * password need to know whether the driver ever chose one (#4672).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_set_at')->nullable()->after('password');
        });

        // Accounts without Steam signed up with a password. Steam-linked accounts may have
        // come through Steam sign-up (random password): treat them as "not set", which only
        // means they can choose one after confirming with Steam or a passkey.
        DB::table('users')->whereNull('steam_id')->update(['password_set_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
