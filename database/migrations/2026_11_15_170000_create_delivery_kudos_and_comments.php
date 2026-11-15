<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kudos and comments on jobs (#4646). Both go with the job and with the driver who wrote them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_kudos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['delivery_id', 'user_id']);
        });

        Schema::create('delivery_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['delivery_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_comments');
        Schema::dropIfExists('delivery_kudos');
    }
};
