<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company goals (#4649): a monthly target per metric. `month` is the UTC month as
     * "YYYY-MM" (a string, so SQLite and MySQL compare it the same way).
     */
    public function up(): void
    {
        Schema::create('company_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->string('metric', 16);
            $table->unsignedBigInteger('target');
            $table->timestamps();

            $table->unique(['company_id', 'month', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_goals');
    }
};
