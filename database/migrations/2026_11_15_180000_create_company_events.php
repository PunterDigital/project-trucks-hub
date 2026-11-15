<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Company events and convoys (#4648). Times are stored in UTC; pages show the viewer's local time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->timestamp('starts_at');
            $table->string('game', 8)->default('ets2');
            $table->string('server', 80)->nullable();
            $table->string('start_city', 80);
            $table->string('start_company', 80)->nullable();
            $table->string('dest_city', 80);
            $table->string('dest_company', 80)->nullable();
            $table->text('route_notes')->nullable();
            $table->unsignedSmallInteger('max_drivers')->nullable();
            $table->boolean('open')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'starts_at']);
            $table->index('starts_at');
        });

        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            $table->timestamps();
            $table->unique(['company_event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('company_events');
    }
};
