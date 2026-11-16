<?php

use App\Admin\Moderation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What {@see Moderation::STAFF_FLAG_PREFIX} put in front of a staff flag before #4743. */
    private const STAFF_FLAG_PREFIX = 'Flagged by staff: ';

    /**
     * Pending review (#4743): a flagged job (by the app or the Hub's checks) stays hidden from
     * everyone but its driver until staff approve or reject it. `review` is null until then.
     * Staff flags from before ("Flag as cheated") become rejections with the same reason.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            // null = not reviewed, 'approved' or 'rejected'.
            $table->string('review', 16)->nullable()->after('staff_reviewed');
            $table->string('review_note', 500)->nullable()->after('review');
            $table->timestamp('reviewed_at')->nullable()->after('review_note');
        });

        DB::table('deliveries')
            ->where('hub_flag_reason', 'like', self::STAFF_FLAG_PREFIX.'%')
            ->orderBy('id')
            ->each(function (object $row) {
                /** @var object{id: int, hub_flag_reason: string, updated_at: string|null} $row */
                DB::table('deliveries')->where('id', $row->id)->update([
                    'review' => 'rejected',
                    'review_note' => mb_substr(mb_substr($row->hub_flag_reason, mb_strlen(self::STAFF_FLAG_PREFIX)), 0, 500),
                    'reviewed_at' => $row->updated_at ?? now(),
                    'hub_flag_reason' => null,
                    'hub_held' => false,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('deliveries')->where('review', 'rejected')->orderBy('id')->each(function (object $row) {
            /** @var object{id: int, review_note: string|null} $row */
            DB::table('deliveries')->where('id', $row->id)->update([
                'hub_flag_reason' => self::STAFF_FLAG_PREFIX.($row->review_note ?? ''),
            ]);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['review', 'review_note', 'reviewed_at']);
        });
    }
};
