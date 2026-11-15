<?php

namespace App\Models;

use App\Achievements\Achievement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One earned achievement (#4645). Written only by App\Achievements\Progress.
 *
 * @property int $id
 * @property int $user_id
 * @property Achievement $achievement
 * @property Carbon $unlocked_at
 */
class DriverAchievement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'achievement' => Achievement::class,
            'unlocked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
