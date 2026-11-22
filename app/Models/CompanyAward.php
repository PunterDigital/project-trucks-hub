<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Driver of the month (#5226): one of a company's top three drivers in a UTC month. Written by
 * `App\Support\CompanyAwards::award()`, never edited.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $month "YYYY-MM", UTC
 * @property int $place 1, 2 or 3
 * @property float $distance_km
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User $user
 */
class CompanyAward extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'place' => 'integer',
            'distance_km' => 'float',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
