<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\CompanyRole;
use App\Enums\Recruitment;
use App\Support\ImageStore;
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
 * @property string|null $logo_path
 * @property string|null $banner_path
 * @property string|null $discord_webhook
 * @property Carbon|null $discord_webhook_disabled_at
 * @property string|null $discord_webhook_error
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
            // Discord webhook (#4652): the URL is the secret, so it's encrypted at rest and
            // never sent back to a page in full (see `discordSummary()`).
            'discord_webhook' => 'encrypted',
            'discord_webhook_disabled_at' => 'datetime',
        ];
    }

    /** @var list<string> */
    protected $hidden = ['discord_webhook'];

    /** The webhook posts only while it's set and hasn't been switched off by failures. */
    public function discordActive(): bool
    {
        return $this->discord_webhook !== null && $this->discord_webhook_disabled_at === null;
    }

    /**
     * What the edit page may know about the webhook: never the URL itself.
     *
     * @return array{set: bool, hint: string|null, disabled_at: string|null, error: string|null}
     */
    public function discordSummary(): array
    {
        $url = $this->discord_webhook;

        return [
            'set' => $url !== null,
            'hint' => $url !== null ? '…'.substr($url, -4) : null,
            'disabled_at' => $this->discord_webhook_disabled_at?->toIso8601String(),
            'error' => $this->discord_webhook_error,
        ];
    }

    protected static function booted(): void
    {
        // Uploaded pictures (#4644) live on disk, not in the row.
        static::deleted(function (Company $company) {
            ImageStore::delete($company->logo_path);
            ImageStore::delete($company->banner_path);
        });
    }

    /** Deletes the logo and banner files and clears both columns (no save). */
    public function forgetImages(): void
    {
        ImageStore::delete($this->logo_path);
        ImageStore::delete($this->banner_path);
        $this->forceFill(['logo_path' => null, 'banner_path' => null]);
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

    /** @return HasMany<CompanyEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CompanyEvent::class);
    }

    /** @return HasMany<CompanyGoal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(CompanyGoal::class);
    }

    /** @return HasMany<CompanyInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(CompanyInvite::class);
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
            'logo' => ImageStore::url($this->logo_path),
        ];
    }
}
