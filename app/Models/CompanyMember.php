<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property CompanyRole $role
 * @property int|null $rank_id
 * @property int|null $pinned_rank_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User $user
 * @property-read CompanyRank|null $rank
 */
#[Fillable(['company_id', 'user_id', 'role'])]
class CompanyMember extends Model
{
    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
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

    /**
     * Company rank (#5223), kept up to date by `App\Support\CompanyRanks`.
     *
     * @return BelongsTo<CompanyRank, $this>
     */
    public function rank(): BelongsTo
    {
        return $this->belongsTo(CompanyRank::class);
    }
}
