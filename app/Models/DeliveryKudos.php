<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One driver's kudos on one job (#4646): at most one per driver and job.
 *
 * @property int $id
 * @property int $delivery_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property-read Delivery $delivery
 * @property-read User $user
 */
class DeliveryKudos extends Model
{
    protected $table = 'delivery_kudos';

    protected $guarded = ['id'];

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
