<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Support\ImageStore;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A blog post (#5150), written and published by staff in the admin panel. Public only once it
 * is published and its `published_at` has come (`scopePublished`): drafts and future-dated
 * posts are 404 for everyone, staff included (they use the preview page).
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $excerpt
 * @property string $body
 * @property string|null $cover_path
 * @property PostStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    public const PER_PAGE = 10;

    /** Slugs: lowercase words joined by hyphens (what `Str::slug` makes). */
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    protected $fillable = ['title', 'slug', 'excerpt', 'body', 'cover_path', 'status', 'published_at'];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A replaced or removed cover, and a deleted post's cover, are never served again.
        static::updated(function (Post $post): void {
            if ($post->wasChanged('cover_path')) {
                ImageStore::delete($post->getOriginal('cover_path'));
            }
        });
        static::deleted(fn (Post $post) => ImageStore::delete($post->cover_path));
    }

    /**
     * Posts anyone can read: published, and not dated in the future.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublic(): bool
    {
        return $this->status === PostStatus::Published
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    /**
     * The body as HTML. Raw HTML in the Markdown is stripped and `javascript:` style links are
     * dropped, so nothing a post contains can run in a reader's browser.
     */
    public function bodyHtml(): string
    {
        return Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
    }

    /** @return array{id: int, title: string, slug: string, excerpt: string, cover: string|null, published_at: string|null} */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'cover' => ImageStore::url($this->cover_path),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
