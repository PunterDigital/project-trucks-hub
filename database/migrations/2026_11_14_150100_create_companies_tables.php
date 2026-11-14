<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('slug', 70)->unique();
            $table->string('tag', 5)->unique();
            $table->text('description')->nullable();
            // open: anyone can join, apply: owner/manager approves, closed: no new members.
            $table->string('recruitment', 10)->default('apply');
            $table->timestamps();
        });

        Schema::create('company_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // A driver belongs to one company at a time.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('driver'); // owner | manager | driver
            $table->timestamps();
        });

        Schema::create('company_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 10)->default('pending'); // pending | accepted | declined | withdrawn
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status'], 'company_apps_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_applications');
        Schema::dropIfExists('company_members');
        Schema::dropIfExists('companies');
    }
};
