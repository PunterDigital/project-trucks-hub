<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A company's Discord webhook (#4652): stored encrypted, switched off after failed posts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('discord_webhook')->nullable();
            $table->timestamp('discord_webhook_disabled_at')->nullable();
            $table->string('discord_webhook_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['discord_webhook', 'discord_webhook_disabled_at', 'discord_webhook_error']);
        });
    }
};
