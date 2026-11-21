<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A company rank (#5223), reached at `min_km` counted kilometres driven for the company. See
 * `App\Support\CompanyRanks`.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $color
 * @property int $min_km
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 */
#[Fillable(['name', 'color', 'min_km'])]
class CompanyRank extends Model
{
    protected function casts(): array
    {
        return [
            'min_km' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return array{id: int, name: string, color: string, min_km: int} */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'min_km' => $this->min_km,
        ];
    }
}
