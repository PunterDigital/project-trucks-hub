<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invite link to a company (#5221). The token is only ever stored hashed; `issue()` returns
 * the plain one once, for the page that shows the link.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $created_by
 * @property string $token_hash
 * @property Carbon|null $expires_at
 * @property int|null $max_uses
 * @property int $uses
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User|null $creator
 */
#[Fillable(['company_id', 'created_by', 'token_hash', 'expires_at', 'max_uses'])]
class CompanyInvite extends Model
{
    /** Tokens in links: letters and digits only, so the route can refuse anything else. */
    public const TOKEN_LENGTH = 40;

    public const TOKEN_PATTERN = '[A-Za-z0-9]{40}';

    /** Live links a company may have at once, so a manager can't fill the table. */
    public const MAX_ACTIVE = 20;

    /** Expiry choices on the form, in days (0 = never). */
    public const EXPIRY_DAYS = [1, 7, 0];

    /** Use limits on the form (0 = unlimited). */
    public const USE_LIMITS = [1, 5, 25, 0];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'uses' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates an invite and returns it with the plain token (never stored).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Company $company, User $creator, int $expiryDays, int $maxUses): array
    {
        $token = Str::random(self::TOKEN_LENGTH);
        $invite = $company->invites()->create([
            'created_by' => $creator->id,
            'token_hash' => self::hashToken($token),
            'expires_at' => $expiryDays > 0 ? now()->addDays($expiryDays) : null,
            'max_uses' => $maxUses > 0 ? $maxUses : null,
        ]);

        return [$invite, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Not revoked, not expired and not used up.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }

    /** Why the link can't be used, or null when it can. */
    public function problem(): ?string
    {
        if ($this->revoked_at !== null) {
            return __('This invite link has been revoked.');
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return __('This invite link has expired.');
        }
        if ($this->max_uses !== null && $this->uses >= $this->max_uses) {
            return __('This invite link has been used up.');
        }

        return null;
    }

    /**
     * Takes one use, atomically: false when the link stopped being usable meanwhile (the last
     * use went to someone else, or it was revoked).
     */
    public function claim(): bool
    {
        $claimed = self::query()->whereKey($this->id)->usable()->increment('uses');
        if ($claimed === 1) {
            $this->uses++;
        }

        return $claimed === 1;
    }

    /** Gives a claimed use back (the join failed after the claim). */
    public function release(): void
    {
        self::query()->whereKey($this->id)->where('uses', '>', 0)->decrement('uses');
        $this->uses = max(0, $this->uses - 1);
    }

    /**
     * What the edit page shows about a live link: never the token.
     *
     * @return array{id: int, created_at: string|null, expires_at: string|null, max_uses: int|null, uses: int, created_by: string|null}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'max_uses' => $this->max_uses,
            'uses' => $this->uses,
            'created_by' => $this->creator?->name,
        ];
    }
}
