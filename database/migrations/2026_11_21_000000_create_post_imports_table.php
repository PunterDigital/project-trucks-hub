<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which repo post files (#5153) have been imported as drafts. Kept apart from `posts`, so a
 * draft staff delete stays deleted: the file is still recorded and never imported again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_imports', function (Blueprint $table) {
            $table->id();
            $table->string('file', 200)->unique();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_imports');
    }
};
