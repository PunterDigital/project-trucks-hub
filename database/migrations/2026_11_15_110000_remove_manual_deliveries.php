<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jobs typed in by hand in ProjectTrucks no longer sync (#4751): remove the ones the Hub got
 * before. The drivers still have them in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('deliveries')->where('source', 'MANUAL')->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the jobs live on in ProjectTrucks.
    }
};
