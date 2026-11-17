<?php

namespace App\Models;

use App\Support\CompanyGoals;
use App\Support\Stats;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A company's monthly target for one metric (#4649). Progress is never stored: it is counted
 * from the company's jobs whenever the page is shown ({@see CompanyGoals}).
 *
 * @property int $id
 * @property int $company_id
 * @property string $month "YYYY-MM", UTC
 * @property string $metric one of {@see self::METRICS}
 * @property int $target
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 */
#[Fillable(['month', 'metric', 'target'])]
class CompanyGoal extends Model
{
    /** Same keys as {@see Stats::totals()}; distance in km, income in euros. */
    public const METRICS = ['distance_km', 'deliveries', 'income'];

    /** Highest target accepted for any metric. */
    public const MAX_TARGET = 100_000_000;

    protected function casts(): array
    {
        return ['target' => 'integer'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
