<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment on a job (#4646). Plain text, up to {@see self::MAX_LENGTH} characters; pages
 * print it as text (no HTML, links not turned into links).
 *
 * @property int $id
 * @property int $delivery_id
 * @property int $user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Delivery $delivery
 * @property-read User $user
 */
class DeliveryComment extends Model
{
    public const MAX_LENGTH = 1000;

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

    /** The comment's author or the job's driver may delete it. */
    public function canBeDeletedBy(?User $user): bool
    {
        return $user !== null && ($user->id === $this->user_id || $user->id === $this->delivery->user_id);
    }
}
