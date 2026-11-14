<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property string|null $steam_id
 * @property string|null $avatar_url
 * @property bool $profile_private
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Carbon|null $password_set_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CompanyMember|null $membership
 * @property-read LiveStatus|null $liveStatus
 */
#[Fillable(['name', 'email', 'password', 'username', 'profile_private'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_set_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'profile_private' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Sanctum tokens hang off a morph, so no foreign key removes them: linked apps must
        // stop working the moment the account goes (#4641). Everything else cascades.
        static::deleting(function (User $user) {
            $user->tokens()->delete();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'username';
    }

    /** @return HasOne<CompanyMember, $this> */
    public function membership(): HasOne
    {
        return $this->hasOne(CompanyMember::class);
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /** @return HasOne<LiveStatus, $this> */
    public function liveStatus(): HasOne
    {
        return $this->hasOne(LiveStatus::class);
    }

    /**
     * Whether the driver chose a password. Steam sign-ups get a random one nobody knows,
     * so they confirm with Steam (or a passkey) instead until they set one (#4672).
     */
    public function hasPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    /** Set a password the driver chose and remember that they did. */
    public function setChosenPassword(string $password): void
    {
        $this->forceFill(['password' => $password, 'password_set_at' => now()])->save();
    }

    public function companyId(): ?int
    {
        return $this->membership?->company_id;
    }

    /**
     * Drivers `$viewer` may see: public profiles, themselves, and private ones in their company.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        $companyId = $viewer?->companyId();

        return $query->where(function (Builder $q) use ($viewer, $companyId) {
            $q->where('users.profile_private', false);

            if ($viewer !== null) {
                $q->orWhere('users.id', $viewer->id);
            }

            if ($companyId !== null) {
                $q->orWhereHas('membership', fn (Builder $m) => $m->where('company_id', $companyId));
            }
        });
    }

    /** Whether `$viewer` may see this driver's profile and jobs. */
    public function isVisibleTo(?User $viewer): bool
    {
        if (! $this->profile_private) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        if ($viewer->is($this)) {
            return true;
        }

        $companyId = $this->companyId();

        return $companyId !== null && $companyId === $viewer->companyId();
    }

    /**
     * The fields any page may show about a driver. Never send the model itself: it carries the
     * email and whatever relations a controller loaded.
     *
     * @return array<string, mixed>
     */
    public function publicSummary(): array
    {
        $company = $this->membership?->company;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar_url,
            'company' => $company ? [
                'name' => $company->name,
                'slug' => $company->slug,
                'tag' => $company->tag,
            ] : null,
        ];
    }
}
