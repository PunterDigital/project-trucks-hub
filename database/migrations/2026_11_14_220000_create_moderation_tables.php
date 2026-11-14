<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moderation (#4651): reports from drivers, staff-hidden jobs, staff-reviewed flags and
     * suspended accounts.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            // App\Models\User (driver), Company or Delivery (job).
            $table->string('reportable_type', 64);
            $table->unsignedBigInteger('reportable_id');
            $table->string('reason', 32);
            $table->text('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            // Hidden by staff: unlike `hidden`, the driver can't show it again.
            $table->boolean('staff_hidden')->default(false)->after('hidden');
            // Staff cleared the Hub's flag: re-syncs don't run the checks on it again.
            $table->boolean('staff_reviewed')->default(false)->after('staff_hidden');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_staff');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['staff_hidden', 'staff_reviewed']);
        });

        Schema::dropIfExists('reports');
    }
};
