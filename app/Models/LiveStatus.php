<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a driver is doing right now, pushed by ProjectTrucks while the game runs. A row older
 * than {@see self::FRESH_SECONDS} means the app stopped reporting (game closed, PC off).
 *
 * @property int $user_id
 * @property string|null $truck_make
 * @property string|null $truck_model
 * @property string|null $cargo_name
 * @property float|null $cargo_mass_kg
 * @property string|null $source_city
 * @property string|null $source_company
 * @property string|null $dest_city
 * @property string|null $dest_company
 * @property int|null $income
 * @property float|null $driven_km
 * @property float|null $distance_left_km
 * @property float|null $progress_pct
 * @property bool $parked
 * @property float|null $pos_x SCS world metres, east
 * @property float|null $pos_z SCS world metres, south
 * @property float|null $heading_deg 0 = north, counter-clockwise (SCS)
 * @property Carbon $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'truck_make', 'truck_model', 'cargo_name', 'cargo_mass_kg', 'source_city',
    'source_company', 'dest_city', 'dest_company', 'income', 'driven_km', 'distance_left_km',
    'progress_pct', 'parked', 'pos_x', 'pos_z', 'heading_deg', 'updated_at',
])]
class LiveStatus extends Model
{
    public const FRESH_SECONDS = 120;

    public const CREATED_AT = null;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'cargo_mass_kg' => 'float',
            'income' => 'integer',
            'driven_km' => 'float',
            'distance_left_km' => 'float',
            'progress_pct' => 'float',
            'parked' => 'boolean',
            'pos_x' => 'float',
            'pos_z' => 'float',
            'heading_deg' => 'float',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<LiveStatus>  $query
     * @return Builder<LiveStatus>
     */
    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('updated_at', '>=', now()->subSeconds(self::FRESH_SECONDS));
    }

    public function isFresh(): bool
    {
        return $this->updated_at->gte(now()->subSeconds(self::FRESH_SECONDS));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $truck = trim(($this->truck_make ?? '').' '.($this->truck_model ?? ''));

        return [
            'truck' => $truck === '' ? null : $truck,
            'on_job' => $this->cargo_name !== null,
            'cargo_name' => $this->cargo_name,
            'cargo_mass_kg' => $this->cargo_mass_kg,
            'source_city' => $this->source_city,
            'dest_city' => $this->dest_city,
            'income' => $this->income,
            'driven_km' => $this->driven_km,
            'distance_left_km' => $this->distance_left_km,
            'progress_pct' => $this->progress_pct,
            'parked' => $this->parked,
            'updated_at' => $this->updated_at->toIso8601String(),
            'driver' => $this->relationLoaded('user') ? $this->user->publicSummary() : null,
        ];
    }
}
