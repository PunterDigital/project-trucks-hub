<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Sync\JobChecks;
use Closure;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int $income
 * @property float $distance_km
 * @property int $duration_sec
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Company|null $company
 */
#[Fillable([
    'user_id', 'client_id', 'company_id', 'game', 'truck_make', 'truck_model',
    'source_city', 'source_company', 'dest_city', 'dest_company', 'cargo_id', 'cargo_name',
    'cargo_mass_kg', 'income', 'distance_km', 'duration_sec', 'avg_speed_kmh', 'fuel_used_l',
    'damage', 'started_at', 'completed_at', 'status', 'source', 'flagged', 'flag_reason', 'hidden',
])]
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cargo_mass_kg' => 'float',
            'income' => 'integer',
            'distance_km' => 'float',
            'duration_sec' => 'integer',
            'avg_speed_kmh' => 'float',
            'fuel_used_l' => 'float',
            'damage' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => DeliveryStatus::class,
            'flagged' => 'boolean',
            'hub_held' => 'boolean',
            'hidden' => 'boolean',
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

    /**
     * Jobs `$viewer` may see: their own, plus everyone else's that aren't hidden and whose
     * driver is public or in the viewer's company.
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
                    ->whereHas('user', function (Builder $u) use ($companyId) {
                        $u->where('profile_private', false);

                        if ($companyId !== null) {
                            $u->orWhereHas('membership', fn (Builder $m) => $m->where('company_id', $companyId));
                        }
                    });
            });
        });
    }

    /**
     * Jobs that count towards stats and leaderboards: delivered (on time or late), not flagged
     * by ProjectTrucks' sanity check or the Hub's own checks ({@see JobChecks}), not hidden.
     *
     * @param  Builder<Delivery>  $query
     * @return Builder<Delivery>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('deliveries.status', DeliveryStatus::counted())
            ->where('deliveries.flagged', false)
            ->whereNull('deliveries.hub_flag_reason')
            ->where('deliveries.hidden', false);
    }

    /**
     * {@see self::scopeCounted()} as a relation constraint, for `withCount` / `withSum`.
     *
     * @return Closure(Builder<Delivery>): Builder<Delivery>
     */
    public static function countedConstraint(): Closure
    {
        return fn (Builder $query) => $query->whereIn('deliveries.status', DeliveryStatus::counted())
            ->where('deliveries.flagged', false)
            ->whereNull('deliveries.hub_flag_reason')
            ->where('deliveries.hidden', false);
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
            'income' => $this->income,
            'distance_km' => $this->distance_km,
            'truck' => $this->truckName(),
            'status' => $this->status->value,
            // Flagged by the app or by the Hub: either way it doesn't count.
            'flagged' => $this->isFlagged(),
            // Not `$this->hidden`: inside the model that is Eloquent's own $hidden property.
            'hidden' => (bool) $this->getAttribute('hidden'),
            'completed_at' => $this->completed_at->toIso8601String(),
            'driver' => $this->relationLoaded('user') ? $this->user->publicSummary() : null,
        ];
    }

    public function isFlagged(): bool
    {
        return $this->flagged || $this->hub_flag_reason !== null;
    }

    public function truckName(): ?string
    {
        $name = trim(($this->truck_make ?? '').' '.($this->truck_model ?? ''));

        return $name === '' ? null : $name;
    }
}
