<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company ranks (#5223). A company sets up to 12 ranks, each reached at a distance driven
     * for the company. `company_members.rank_id` is the member's current rank (stored so pages
     * and the promotion check need no sums), `pinned_rank_id` a rank an owner or manager set by
     * hand: automatic promotion never puts the member below it. No ranks = both null, the old
     * behaviour. min_km isn't unique in the database: the edit page replaces the whole list, and
     * a swap of two thresholds would trip a unique index half way. `CompanyRanks::save()`
     * refuses duplicates instead.
     */
    public function up(): void
    {
        Schema::create('company_ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 7);
            $table->unsignedInteger('min_km');
            $table->timestamps();

            $table->index(['company_id', 'min_km']);
        });

        Schema::table('company_members', function (Blueprint $table) {
            $table->foreignId('rank_id')->nullable()->constrained('company_ranks')->nullOnDelete();
            $table->foreignId('pinned_rank_id')->nullable()->constrained('company_ranks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_rank_id');
            $table->dropConstrainedForeignId('rank_id');
        });

        Schema::dropIfExists('company_ranks');
    }
};
