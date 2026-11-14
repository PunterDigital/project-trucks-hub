<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\CompanyRole;
use App\Enums\Recruitment;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $tag
 * @property string|null $description
 * @property Recruitment $recruitment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'tag', 'description', 'recruitment'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'recruitment' => Recruitment::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<CompanyMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class);
    }

    /** @return HasMany<CompanyApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(CompanyApplication::class);
    }

    /** @return HasMany<CompanyApplication, $this> */
    public function pendingApplications(): HasMany
    {
        return $this->applications()->where('status', ApplicationStatus::Pending);
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function roleOf(?User $user): ?CompanyRole
    {
        if ($user === null) {
            return null;
        }

        $member = $user->membership;

        return $member !== null && $member->company_id === $this->id ? $member->role : null;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'tag' => $this->tag,
        ];
    }
}
