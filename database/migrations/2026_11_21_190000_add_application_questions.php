<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Application questions (#5222). A company keeps its questions as a JSON list
     * (`App\Support\ApplicationQuestions`); each application keeps a snapshot of the questions
     * it was asked with the answers, so editing the questions later never changes old answers.
     * Null on both = no questions, the old behaviour.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('application_questions')->nullable();
        });

        Schema::table('company_applications', function (Blueprint $table) {
            $table->json('answers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_applications', function (Blueprint $table) {
            $table->dropColumn('answers');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('application_questions');
        });
    }
};
