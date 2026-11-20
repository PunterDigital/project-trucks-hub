<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One repo post file that `App\Support\PostDrafts` has handled (#5153): imported as a draft,
 * or skipped for good because its address was already taken.
 *
 * @property int $id
 * @property string $file
 * @property int|null $post_id
 * @property string $outcome
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PostImport extends Model
{
    public const IMPORTED = 'imported';

    public const SLUG_TAKEN = 'slug_taken';

    protected $fillable = ['file', 'post_id', 'outcome'];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
