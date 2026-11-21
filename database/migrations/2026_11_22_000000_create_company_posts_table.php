<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company news (#5224). Owners and managers post to their company; `visibility` is
     * `public` (anyone) or `members`. At most one post per company is pinned (the controller
     * unpins the others). `users.bell_opt_outs` lists the bell notices a driver switched off
     * in Settings → Notifications (only `company_news` so far); null = everything on.
     */
    public function up(): void
    {
        Schema::create('company_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->string('visibility', 10)->default('members');
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('bell_opt_outs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bell_opt_outs');
        });

        Schema::dropIfExists('company_posts');
    }
};
