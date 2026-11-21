<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A company news post (#5224). Owners and managers write them; `public` posts show to
 * everyone on the company page, `members` posts only to the company's members.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $created_by
 * @property string $title
 * @property string $body
 * @property string $visibility
 * @property Carbon|null $pinned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User|null $author
 */
class CompanyPost extends Model
{
    public const VISIBILITIES = ['members', 'public'];

    public const TITLE_MAX = 120;

    public const BODY_MAX = 5000;

    /** How many posts the company page shows (the pinned one comes on top of these). */
    public const SHOWN = 10;

    protected $fillable = ['title', 'body', 'visibility'];

    protected function casts(): array
    {
        return ['pinned_at' => 'datetime'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /**
     * Posts the viewer may read: public ones, plus members-only ones for the company's own
     * members. Suspended drivers see public posts only.
     *
     * @param  Builder<CompanyPost>  $query
     * @return Builder<CompanyPost>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        $companyId = $viewer !== null && ! $viewer->isSuspended() ? $viewer->companyId() : null;

        return $query->where(fn (Builder $q) => $q
            ->where('company_posts.visibility', 'public')
            ->when($companyId !== null, fn (Builder $q) => $q->orWhere('company_posts.company_id', $companyId)));
    }

    /**
     * Pinned first, then newest.
     *
     * @param  Builder<CompanyPost>  $query
     * @return Builder<CompanyPost>
     */
    public function scopeFeedOrder(Builder $query): Builder
    {
        return $query->orderByRaw('company_posts.pinned_at IS NULL')
            ->latest('company_posts.created_at')
            ->latest('company_posts.id');
    }

    /** @return array<string, mixed> */
    public function summary(?User $viewer): array
    {
        $author = $this->author;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'visibility' => $this->visibility,
            'pinned' => $this->pinned_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'edited' => $this->updated_at !== null && $this->created_at !== null
                && $this->updated_at->gt($this->created_at->copy()->addMinute()),
            // Same rule as everywhere else: a private or suspended author shows as "Private driver".
            'author' => $author === null ? null : ($author->isVisibleTo($viewer)
                ? ['name' => $author->name, 'username' => $author->username, 'avatar' => $author->avatar()]
                : ['name' => __('Private driver'), 'username' => null, 'avatar' => null]),
            'company' => $this->relationLoaded('company') ? $this->company->summary() : null,
        ];
    }
}
