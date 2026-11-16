<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Daily storage snapshots for the admin Storage page (#4667): one row per day and item
// (`StorageStats::ITEMS` plus the disk's total/free).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('item', 32);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('files')->nullable();
            $table->timestamps();
            $table->unique(['date', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_snapshots');
    }
};
