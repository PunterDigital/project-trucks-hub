<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\AdminAuditLog;
use App\Models\Post;
use App\Models\PostImport;
use App\Models\User;
use App\Support\PostDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** Blog drafts written as repo files (#5153): imported once, as drafts, never changed afterwards. */
class PostDraftsTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = storage_path('framework/testing/post-drafts-'.uniqid());
        File::ensureDirectoryExists($this->folder);
        config(['blog.drafts_path' => $this->folder]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    private function writePost(string $file, string $title = 'Notes from the road', string $excerpt = 'What we learned this month.', string $body = 'Hello **drivers**.'): void
    {
        $data = var_export(['title' => $title, 'excerpt' => $excerpt, 'body' => $body], true);
        File::put($this->folder.'/'.$file, "<?php\n\nreturn {$data};\n");
    }

    public function test_a_new_file_becomes_a_draft_once(): void
    {
        $this->writePost('2026-11-21-notes-from-the-road.php');

        $results = PostDrafts::import();

        $this->assertSame([[
            'file' => '2026-11-21-notes-from-the-road.php',
            'outcome' => PostImport::IMPORTED,
            'message' => 'Draft "Notes from the road" created.',
        ]], $results);
        $post = Post::query()->sole();
        $this->assertSame('notes-from-the-road', $post->slug);
        $this->assertSame('What we learned this month.', $post->excerpt);
        $this->assertSame('Hello **drivers**.', $post->body);
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertNull($post->published_at);
        $this->assertFalse($post->isPublic());
        $this->get('/blog/notes-from-the-road')->assertNotFound();

        $log = AdminAuditLog::query()->where('action', 'post.imported')->sole();
        $this->assertSame($post->id, $log->target_id);
        $this->assertSame('Command line', $log->staff_name);

        // Running again changes nothing, even after staff edited the draft.
        $post->update(['title' => 'Edited by staff']);
        $this->assertSame([], PostDrafts::import());
        $this->assertSame('Edited by staff', $post->fresh()?->title);
        $this->assertSame(1, Post::query()->count());
    }

    public function test_a_deleted_draft_is_not_brought_back(): void
    {
        $this->writePost('2026-11-21-notes-from-the-road.php');
        PostDrafts::import();

        Post::query()->sole()->delete();

        $this->assertSame([], PostDrafts::import());
        $this->assertSame(0, Post::query()->count());
        $this->assertNull(PostImport::query()->sole()->post_id);
    }

    public function test_a_taken_address_is_skipped_for_good_and_the_post_left_alone(): void
    {
        $existing = Post::factory()->create(['slug' => 'notes-from-the-road', 'title' => 'Hand-written']);
        $this->writePost('2026-11-21-notes-from-the-road.php');

        $results = PostDrafts::import();

        $this->assertSame(PostImport::SLUG_TAKEN, $results[0]['outcome']);
        $this->assertSame('Hand-written', $existing->fresh()?->title);
        $this->assertSame(1, Post::query()->count());
        $this->assertSame([], PostDrafts::import());
    }

    public function test_invalid_files_are_reported_and_retried_after_a_fix(): void
    {
        $this->writePost('notes-from-the-road.php');
        $this->writePost('2026-11-21-feed.php');
        $this->writePost('2026-11-21-too-long.php', title: str_repeat('a', 161));
        File::put($this->folder.'/2026-11-21-not-an-array.php', "<?php\n\nreturn 'nope';\n");

        $results = PostDrafts::import();

        $this->assertCount(4, $results);
        $this->assertSame(['invalid'], array_values(array_unique(array_column($results, 'outcome'))));
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, PostImport::query()->count());

        $this->artisan('hub:import-post-drafts')->assertFailed();

        // Fixed: the next run imports it.
        $this->writePost('2026-11-21-too-long.php', title: 'Short enough');
        $this->assertSame(PostImport::IMPORTED, collect(PostDrafts::import())->firstWhere('file', '2026-11-21-too-long.php')['outcome'] ?? null);
    }

    public function test_the_command_imports_and_says_so(): void
    {
        $this->artisan('hub:import-post-drafts')->expectsOutput('No new post files.')->assertSuccessful();

        $this->writePost('2026-11-21-notes-from-the-road.php');
        $this->artisan('hub:import-post-drafts')
            ->expectsOutput('2026-11-21-notes-from-the-road.php: Draft "Notes from the road" created.')
            ->assertSuccessful();
    }

    public function test_opening_the_blog_posts_list_imports_new_files(): void
    {
        $staff = User::factory()->withTwoFactor()->create();
        $staff->forceFill(['is_staff' => true])->save();
        $this->actingAs($staff);
        $this->writePost('2026-11-21-notes-from-the-road.php');

        Livewire::test(ListPosts::class)
            ->assertNotified('1 new draft from the repo')
            ->assertCanSeeTableRecords(Post::query()->get());

        $this->assertSame($staff->id, AdminAuditLog::query()->where('action', 'post.imported')->sole()->staff_id);
    }
}
