<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A company's scheduled event or convoy (#4648). Every company is public, so every event is
 * too; members answer it, and anyone signed in can when it is `open`.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $created_by
 * @property string $title
 * @property Carbon $starts_at
 * @property string $game
 * @property string|null $server
 * @property string $start_city
 * @property string|null $start_company
 * @property string $dest_city
 * @property string|null $dest_company
 * @property string|null $route_notes
 * @property int|null $max_drivers
 * @property bool $open
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 */
class CompanyEvent extends Model
{
    /** An event stays "upcoming" for this long after it starts (a convoy takes a while). */
    public const UPCOMING_GRACE_HOURS = 3;

    public const GAMES = ['ets2' => 'Euro Truck Simulator 2', 'ats' => 'American Truck Simulator'];

    protected $guarded = ['id', 'company_id', 'created_by', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'max_drivers' => 'integer',
            'open' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<EventRsvp, $this> */
    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class);
    }

    /**
     * Not cancelled, and starting later than {@see self::UPCOMING_GRACE_HOURS} ago.
     *
     * @param  Builder<CompanyEvent>  $query
     * @return Builder<CompanyEvent>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->where('starts_at', '>=', now()->subHours(self::UPCOMING_GRACE_HOURS))
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isOver(): bool
    {
        return $this->starts_at->lt(now()->subHours(self::UPCOMING_GRACE_HOURS));
    }

    /** Members can answer; with `open`, any signed-in driver can. */
    public function canAnswer(?User $user): bool
    {
        if ($user === null || $user->isSuspended()) {
            return false;
        }

        return $this->open || $user->companyId() === $this->company_id;
    }

    public function goingCount(): int
    {
        return $this->rsvps()->where('status', RsvpStatus::Going)->count();
    }

    public function isFull(): bool
    {
        return $this->max_drivers !== null && $this->goingCount() >= $this->max_drivers;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'starts_at' => $this->starts_at->toIso8601String(),
            'game' => $this->game,
            'server' => $this->server,
            'start_city' => $this->start_city,
            'dest_city' => $this->dest_city,
            'open' => $this->open,
            'max_drivers' => $this->max_drivers,
            'cancelled' => $this->isCancelled(),
            'going' => (int) ($this->getAttribute('going_count') ?? $this->goingCount()),
            'company' => $this->relationLoaded('company') ? $this->company->summary() : null,
        ];
    }
}
