<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs synced from ProjectTrucks. Called "deliveries" here because Laravel's queue already owns
 * the `jobs` table; the UI still says "jobs".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The job's id in the driver's local ProjectTrucks database (a UUID). Re-syncing the
            // same job updates this row.
            $table->string('client_id', 64);
            // The driver's company when the job was first synced; stays with the job if they leave.
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('game', 8)->default('ets2');
            $table->string('truck_make', 60)->nullable();
            $table->string('truck_model', 60)->nullable();
            $table->string('source_city', 80);
            $table->string('source_company', 80);
            $table->string('dest_city', 80);
            $table->string('dest_company', 80);
            $table->string('cargo_id', 80);
            $table->string('cargo_name', 120);
            $table->decimal('cargo_mass_kg', 10, 1);
            $table->bigInteger('income');
            $table->decimal('distance_km', 9, 2);
            $table->unsignedInteger('duration_sec');
            $table->decimal('avg_speed_kmh', 6, 2);
            $table->decimal('fuel_used_l', 9, 2)->nullable();
            $table->decimal('damage', 5, 4)->nullable(); // 0..1
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->string('status', 10); // DELIVERED | LATE | CANCELLED
            $table->string('source', 10); // TELEMETRY | MANUAL
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason')->nullable();
            // The driver hid this job from everyone else.
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'client_id'], 'deliveries_user_client_unique');
            $table->index('completed_at', 'deliveries_completed_idx');
            $table->index(['company_id', 'completed_at'], 'deliveries_company_completed_idx');
            $table->index(['user_id', 'completed_at'], 'deliveries_user_completed_idx');
        });

        Schema::create('live_statuses', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('truck_make', 60)->nullable();
            $table->string('truck_model', 60)->nullable();
            $table->string('cargo_name', 120)->nullable();
            $table->decimal('cargo_mass_kg', 10, 1)->nullable();
            $table->string('source_city', 80)->nullable();
            $table->string('source_company', 80)->nullable();
            $table->string('dest_city', 80)->nullable();
            $table->string('dest_company', 80)->nullable();
            $table->bigInteger('income')->nullable();
            $table->decimal('driven_km', 9, 2)->nullable();
            $table->decimal('distance_left_km', 9, 2)->nullable();
            $table->decimal('progress_pct', 5, 2)->nullable();
            $table->boolean('parked')->default(false);
            $table->timestamp('updated_at')->index('live_statuses_updated_idx');
        });

        Schema::create('device_authorizations', function (Blueprint $table) {
            $table->id();
            // sha256 of the secret code the app polls with.
            $table->string('device_code_hash', 64)->unique();
            // The short code the driver types on the website (stored without the dash).
            $table->string('user_code', 8)->unique();
            $table->string('client_name', 100);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_authorizations');
        Schema::dropIfExists('live_statuses');
        Schema::dropIfExists('deliveries');
    }
};
