<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Daily per-table size snapshots for the admin Database page (#4666). `table_name` '*' is the
// whole database.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_table_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('data_bytes')->nullable();
            $table->unsignedBigInteger('index_bytes')->nullable();
            $table->timestamps();
            $table->unique(['date', 'table_name'], 'db_snapshots_date_table_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_table_snapshots');
    }
};
