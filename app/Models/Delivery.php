<?php

namespace App\Models;

use App\Achievements\TopTenWeeks;
use App\Enums\DeliveryStatus;
use App\Sync\JobChecks;
use Closure;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A job synced from a driver's ProjectTrucks.
 *
 * @property int $id
 * @property int $user_id
 * @property string $client_id
 * @property int|null $company_id
 * @property string $game
 * @property string|null $truck_make
 * @property string|null $truck_model
 * @property string $source_city
 * @property string $source_company
 * @property string $dest_city
 * @property string $dest_company
 * @property string $cargo_id
 * @property string $cargo_name
 * @property float $cargo_mass_kg
 * @property int $income The offered (declared) income; see {@see self::effectiveIncome()}
 * @property int|null $game_revenue What the game paid (#5227), null from apps before 0.12
 * @property int|null $earned_xp
 * @property float|null $cargo_damage Cargo damage at delivery, 0 to 1
 * @property int|null $costs Tolls, fines and ferry/train fares paid on the job, in total
 * @property int|null $cancel_penalty
 * @property float $distance_km
 * @property int $duration_sec
 * @property int|null $game_duration_min In-game minutes between pickup and delivery (#4771), null from apps before 0.11
 * @property float $avg_speed_kmh
 * @property float|null $fuel_used_l
 * @property float|null $damage
 * @property Carbon $started_at
 * @property Carbon $completed_at
 * @property DeliveryStatus $status
 * @property string $source
 * @property bool $flagged
 * @property string|null $flag_reason
 * @property string|null $hub_flag_reason
 * @property bool $hub_held
 * @property bool $hidden
 * @property bool $staff_hidden
 * @property bool $staff_reviewed
 * @property string|null $review null until staff review a flagged job (#4743): 'approved' or 'rejected'
 * @property string|null $review_note
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Company|null $company
 */
#[Fillable([
    'user_id', 'client_id', 'company_id', 'game', 'truck_make', 'truck_model',
    'source_city', 'source_company', 'dest_city', 'dest_company', 'cargo_id', 'cargo_name',
    'cargo_mass_kg', 'income', 'game_revenue', 'earned_xp', 'cargo_damage', 'costs', 'cancel_penalty', 'distance_km', 'duration_sec', 'game_duration_min', 'avg_speed_kmh', 'fuel_used_l',
    'damage', 'started_at', 'completed_at', 'status', 'source', 'flagged', 'flag_reason', 'hidden',
])]
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory;

    /** Staff review of a flagged job (#4743), stored in `review`. */
    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /** {@see self::reviewState()} for a flagged job nobody has reviewed yet. */
    public const REVIEW_PENDING = 'pending';

    /**
     * A job's income in every total (#5227): what the game paid when the app sent it, else the
     * offered income. The same rule as ProjectTrucks' own stats.
     */
    public const INCOME_SQL = 'COALESCE(deliveries.game_revenue, deliveries.income)';

    /** Shown to the driver next to a pending job. */
    public const REVIEW_TIME = 'Reviews usually take no more than 12 hours.';

    /** What decides whether and how far a job counts on a week's board ({@see TopTenWeeks}). */
    private const BOARD_FIELDS = [
        'user_id', 'status', 'distance_km', 'completed_at', 'hidden', 'staff_hidden',
        'flagged', 'hub_flag_reason', 'review',
    ];

    protected static function booted(): void
    {
        // "Top ten" caches each finished week's leaders (#5732): a job in such a week that is
        // synced late, changed, hidden, reviewed or deleted forgets that week (both weeks when
        // its completion time moved).
        static::saved(function (Delivery $job) {
            if ($job->wasRecentlyCreated || $job->wasChanged(self::BOARD_FIELDS)) {
                TopTenWeeks::forget($job->completed_at, $job->getOriginal('completed_at'));
            }
        });
        static::deleted(fn (Delivery $job) => TopTenWeeks::forget($job->completed_at));
    }

    protected function casts(): array
    {
        return [
            'cargo_mass_kg' => 'float',
            'income' => 'integer',
            'game_revenue' => 'integer',
            'earned_xp' => 'integer',
            'cargo_damage' => 'float',
            'costs' => 'integer',
            'cancel_penalty' => 'integer',
            'distance_km' => 'float',
            'duration_sec' => 'integer',
            'game_duration_min' => 'integer',
            'avg_speed_kmh' => 'float',
            'fuel_used_l' => 'float',
            'damage' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => DeliveryStatus::class,
            'flagged' => 'boolean',
            'hub_held' => 'boolean',
            'hidden' => 'boolean',
            'staff_hidden' => 'boolean',
            'staff_reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<DeliveryKudos, $this> */
    public function kudos(): HasMany
    {
        return $this->hasMany(DeliveryKudos::class);
    }

    /** @return HasMany<DeliveryComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(DeliveryComment::class);
    }

    /**
     * Kudos and comment counts for job lists (#4646), plus whether `$viewer` gave kudos.
     * Suspended drivers' kudos and comments are left out, like the rest of what they did.
     *
     * @param  Builder<Delivery>  $query
     * @return Builder<Delivery>
     */
    public function scopeWithReactions(Builder $query, ?User $viewer): Builder
    {
        $query->withCount([
            'kudos' => fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereNull('suspended_at')),
            'comments' => fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->whereNull('suspended_at')),
        ]);

        if ($viewer !== null) {
            $query->withExists(['kudos as kudos_given' => fn (Builder $q) => $q->where('user_id', $viewer->id)]);
        }

        return $query;
    }

    /**
     * Jobs `$viewer` may see: their own, plus everyone else's that aren't hidden, aren't
     * waiting for (or failed) a staff review (#4743), and whose driver is public or in the
     * viewer's company.
     *
     * @param  Builder<Delivery>  $query
     * @return Builder<Delivery>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        $companyId = $viewer?->companyId();

        return $query->where(function (Builder $q) use ($viewer, $companyId) {
            if ($viewer !== null) {
                $q->where('deliveries.user_id', $viewer->id);
            }

            $q->orWhere(function (Builder $q) use ($companyId) {
                $q->where('deliveries.hidden', false)
                    ->where('deliveries.staff_hidden', false)
                    ->where(self::clearedConstraint())
                    ->whereHas('user', function (Builder $u) use ($companyId) {
                        $u->whereNull('suspended_at')->where(function (Builder $u) use ($companyId) {
                            $u->where('profile_private', false);

                            if ($companyId !== null) {
                                $u->orWhereHas('membership', fn (Builder $m) => $m->where('company_id', $companyId));
                            }
                        });
                    });
            });
        });
    }

    /**
     * Flagged jobs nobody on staff has looked at yet (#4743): the admin's "Pending review" tab.
     *
     * @param  Builder<Delivery>  $query
     * @return Builder<Delivery>
     */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->whereNull('deliveries.review')
            ->where(fn (Builder $q) => $q->where('deliveries.flagged', true)->orWhereNotNull('deliveries.hub_flag_reason'));
    }

    /**
     * Not held back by a review (#4743): approved by staff, or never flagged (by ProjectTrucks'
     * sanity check or the Hub's own checks, {@see JobChecks}) and not rejected.
     *
     * @return Closure(Builder<Delivery>): Builder<Delivery>
     */
    private static function clearedConstraint(): Closure
    {
        return fn (Builder $q) => $q->where('deliveries.review', self::REVIEW_APPROVED)
            ->orWhere(fn (Builder $q) => $q->whereNull('deliveries.review')
                ->where('deliveries.flagged', false)
                ->whereNull('deliveries.hub_flag_reason'));
    }

    /**
     * Jobs that count towards stats and leaderboards: delivered (on time or late), not held
     * back by a review (flagged and not approved, or rejected by staff, #4743), not hidden (by
     * the driver or by staff), and not from a suspended driver (#4651).
     *
     * @param  Builder<Delivery>  $query
     * @return Builder<Delivery>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return self::applyCounted($query);
    }

    /**
     * Counted, but still including a suspended driver's jobs: levels and achievements (#4645)
     * are the driver's own record, so a suspension hides them without taking them away.
     *
     * @param  Builder<Delivery>  $query
     * @return Builder<Delivery>
     */
    public function scopeEarned(Builder $query): Builder
    {
        return self::applyCounted($query, skipSuspended: false);
    }

    /**
     * @param  Builder<Delivery>  $query
     * @return Builder<Delivery>
     */
    private static function applyCounted(Builder $query, bool $skipSuspended = true): Builder
    {
        $query->whereIn('deliveries.status', DeliveryStatus::counted())
            ->where(self::clearedConstraint())
            ->where('deliveries.hidden', false)
            ->where('deliveries.staff_hidden', false);

        return ! $skipSuspended ? $query : $query
            ->whereNotExists(fn (\Illuminate\Database\Query\Builder $u) => $u->selectRaw('1')
                ->from('users as suspended_drivers')
                ->whereColumn('suspended_drivers.id', 'deliveries.user_id')
                ->whereNotNull('suspended_drivers.suspended_at'));
    }

    /**
     * {@see self::scopeCounted()} as a relation constraint, for `withCount` / `withSum`.
     *
     * @return Closure(Builder<Delivery>): Builder<Delivery>
     */
    public static function countedConstraint(): Closure
    {
        return fn (Builder $query) => self::applyCounted($query);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'source_city' => $this->source_city,
            'dest_city' => $this->dest_city,
            'cargo_name' => $this->cargo_name,
            'cargo_mass_kg' => $this->cargo_mass_kg,
            'income' => $this->effectiveIncome(),
            'distance_km' => $this->distance_km,
            'truck' => $this->truckName(),
            'status' => $this->status->value,
            // Pending / rejected only ever reach the driver: everyone else can't see such a job.
            'review' => $this->reviewState(),
            // Not `$this->hidden`: inside the model that is Eloquent's own $hidden property.
            'hidden' => (bool) $this->getAttribute('hidden'),
            'staff_hidden' => $this->staff_hidden,
            'completed_at' => $this->completed_at->toIso8601String(),
            'driver_id' => $this->user_id,
            'driver' => $this->relationLoaded('user') ? $this->user->publicSummary() : null,
            // Filled by scopeWithReactions() (#4646).
            'kudos' => (int) ($this->getAttribute('kudos_count') ?? 0),
            'kudos_given' => (bool) ($this->getAttribute('kudos_given') ?? false),
            'comments' => (int) ($this->getAttribute('comments_count') ?? 0),
        ];
    }

    /** {@see self::INCOME_SQL} for one job. */
    public function effectiveIncome(): int
    {
        return $this->game_revenue ?? $this->income;
    }

    /** Flagged by the app or by the Hub's checks, whatever staff decided since. */
    public function isFlagged(): bool
    {
        return $this->flagged || $this->hub_flag_reason !== null;
    }

    /**
     * Where the job stands with staff (#4743): 'pending' (flagged, not reviewed yet),
     * 'approved', 'rejected', or null when it was never flagged nor rejected.
     */
    public function reviewState(): ?string
    {
        return match (true) {
            $this->review === self::REVIEW_APPROVED, $this->review === self::REVIEW_REJECTED => $this->review,
            $this->isFlagged() => self::REVIEW_PENDING,
            default => null,
        };
    }

    /** Not held back by a review: the same rule as the visibility and counted scopes. */
    public function isCleared(): bool
    {
        return in_array($this->reviewState(), [null, self::REVIEW_APPROVED], true);
    }

    /**
     * Why the job is waiting for a review, in the driver's words: the app's and the Hub's
     * reasons. Empty when nothing flagged it.
     *
     * @return list<string>
     */
    public function flagReasons(): array
    {
        $reasons = [];
        if ($this->flagged) {
            $reasons[] = $this->flag_reason ?? __('ProjectTrucks flagged it');
        }
        if ($this->hub_flag_reason !== null) {
            array_push($reasons, ...explode('; ', $this->hub_flag_reason));
        }

        return array_values(array_unique($reasons));
    }

    public function truckName(): ?string
    {
        $name = trim(($this->truck_make ?? '').' '.($this->truck_model ?? ''));

        return $name === '' ? null : $name;
    }
}
