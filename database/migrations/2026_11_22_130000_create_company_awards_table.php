<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Driver of the month (#5226): each company's top three drivers by counted distance driven
     * for it in a UTC month ("YYYY-MM"), written once by `hub:monthly-awards` on the 1st. The
     * unique keys make a rerun (or two at once) a no-op.
     */
    public function up(): void
    {
        Schema::create('company_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->unsignedTinyInteger('place');
            $table->double('distance_km');
            $table->timestamps();

            $table->unique(['company_id', 'month', 'place']);
            $table->unique(['company_id', 'month', 'user_id']);
            $table->index(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_awards');
    }
};
