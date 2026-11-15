<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One driver's answer to one event (#4648).
 *
 * @property int $id
 * @property int $company_event_id
 * @property int $user_id
 * @property RsvpStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CompanyEvent $event
 * @property-read User $user
 */
class EventRsvp extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => RsvpStatus::class];
    }

    /** @return BelongsTo<CompanyEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class, 'company_event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
