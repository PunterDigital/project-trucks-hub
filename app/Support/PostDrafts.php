<?php

namespace App\Support;

use App\Admin\Audit;
use App\Enums\PostStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\PostImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Blog post drafts written in the repo (#5153). Scheduled Claude runs write a post as
 * `resources/posts/<Y-m-d>-<slug>.php` in a pull request; once it is deployed, `import()`
 * turns it into a **draft** in Admin → Blog posts, and staff publish it from there.
 *
 * Each file returns `['title' => …, 'excerpt' => …, 'body' => <<<'MD' … MD]` (title at most
 * 160 characters, excerpt at most 300, body Markdown). The address is the file name without
 * the date. A file is imported once: `post_imports` remembers it, so a draft staff delete or
 * a post they edit is never brought back or changed. A file whose address is already taken is
 * recorded as skipped. An invalid file is reported and left for a fix.
 *
 * Runs hourly (`hub:import-post-drafts`) and whenever staff open the Blog posts list, since
 * the scheduler may not be running on the server.
 */
class PostDrafts
{
    private const FILE_PATTERN = '/^\d{4}-\d{2}-\d{2}-('.Post::SLUG_PATTERN.')$/';

    /**
     * @return list<array{file: string, outcome: string, message: string}> what happened to each
     *                                                                     file not imported before
     */
    public static function import(): array
    {
        $folder = (string) config('blog.drafts_path');
        $files = glob(rtrim($folder, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);

        $done = PostImport::query()->pluck('file')->all();
        $results = [];

        foreach ($files as $path) {
            $file = basename($path);
            if (in_array($file, $done, true)) {
                continue;
            }

            $results[] = ['file' => $file, ...self::importFile($path, $file)];
        }

        return $results;
    }

    /** @return array{outcome: string, message: string} */
    private static function importFile(string $path, string $file): array
    {
        $name = basename($file, '.php');
        if (preg_match(self::FILE_PATTERN, $name, $match) !== 1) {
            return ['outcome' => 'invalid', 'message' => 'The file name must be <Y-m-d>-<address>.php.'];
        }
        $slug = $match[1];

        $data = (static fn (string $path): mixed => require $path)($path);
        $validator = Validator::make(is_array($data) ? $data : [], [
            'title' => ['required', 'string', 'max:160'],
            'excerpt' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string'],
        ]);
        if ($validator->fails() || strlen($slug) > 160 || in_array($slug, PostResource::RESERVED_SLUGS, true)) {
            $errors = $validator->errors()->all() ?: ['The address "'.$slug.'" can\'t be used.'];

            return ['outcome' => 'invalid', 'message' => implode(' ', $errors)];
        }

        /** @var array{title: string, excerpt: string, body: string} $valid */
        $valid = $validator->validated();

        try {
            return DB::transaction(function () use ($file, $slug, $valid): array {
                if (Post::query()->where('slug', $slug)->exists()) {
                    PostImport::query()->create(['file' => $file, 'outcome' => PostImport::SLUG_TAKEN]);

                    return ['outcome' => PostImport::SLUG_TAKEN, 'message' => 'A post already uses /blog/'.$slug.'; skipped for good.'];
                }

                $post = Post::query()->create([
                    'title' => $valid['title'],
                    'slug' => $slug,
                    'excerpt' => $valid['excerpt'],
                    'body' => $valid['body'],
                    'status' => PostStatus::Draft,
                    'published_at' => null,
                ]);
                PostImport::query()->create(['file' => $file, 'post_id' => $post->id, 'outcome' => PostImport::IMPORTED]);
                Audit::record('post.imported', $post, null, [...PostResource::auditFields($post), 'file' => $file]);

                return ['outcome' => PostImport::IMPORTED, 'message' => 'Draft "'.$post->title.'" created.'];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request imported it at the same moment.
            return ['outcome' => 'skipped', 'message' => 'Imported by another process just now.'];
        }
    }
}
