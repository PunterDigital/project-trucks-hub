<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\NoticeType;
use App\Http\Controllers\Settings\PrivacyController;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\DeliveryComment;
use App\Models\DeliveryKudos;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Kudos and comments on jobs (#4646). */
class JobReactionsTest extends TestCase
{
    use RefreshDatabase;

    private function job(array $driver = [], array $job = []): Delivery
    {
        $owner = User::factory()->create(['name' => 'Olga Owner', ...$driver]);

        return Delivery::factory()->for($owner)->create(['source_city' => 'Calais', 'dest_city' => 'Paris', ...$job]);
    }

    /** Back to a signed-out visitor: actingAs() sticks for the rest of the test. */
    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    public function test_a_driver_gives_one_kudos_and_can_take_it_back(): void
    {
        $job = $this->job();
        $fan = User::factory()->create();

        $this->actingAs($fan)->post(route('jobs.kudos.give', $job))->assertRedirect();
        $this->actingAs($fan)->post(route('jobs.kudos.give', $job))->assertRedirect();
        $this->assertSame(1, DeliveryKudos::query()->where('delivery_id', $job->id)->count());

        $this->actingAs($fan)->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page
            ->where('job.kudos', 1)
            ->where('job.kudos_given', true)
            ->where('job.driver_id', $job->user_id));
        $this->asGuest()->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page
            ->where('job.kudos', 1)
            ->where('job.kudos_given', false));

        $this->actingAs($fan)->delete(route('jobs.kudos.take', $job))->assertRedirect();
        $this->assertSame(0, DeliveryKudos::query()->count());
    }

    public function test_counts_show_in_the_feed_profile_and_dashboard(): void
    {
        $job = $this->job();
        $fan = User::factory()->create();
        DeliveryKudos::query()->create(['delivery_id' => $job->id, 'user_id' => $fan->id]);
        $job->comments()->create(['user_id' => $fan->id, 'body' => 'Nice run']);

        $this->actingAs($fan)->get(route('home'))->assertInertia(fn ($page) => $page
            ->where('latest.0.kudos', 1)
            ->where('latest.0.kudos_given', true)
            ->where('latest.0.comments', 1));
        $this->get(route('drivers.show', $job->user))->assertInertia(fn ($page) => $page
            ->where('jobs.data.0.kudos', 1)
            ->where('jobs.data.0.comments', 1));
        $this->actingAs($job->user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('recent.0.kudos', 1)
            ->where('recent.0.kudos_given', false));
    }

    public function test_no_kudos_for_your_own_job_and_guests_must_sign_in(): void
    {
        $job = $this->job();

        $this->actingAs($job->user)->post(route('jobs.kudos.give', $job))->assertForbidden();
        $this->asGuest()->post(route('jobs.kudos.give', $job))->assertRedirect(route('login'));
        $this->post(route('jobs.comments.store', $job), ['body' => 'Hi'])->assertRedirect(route('login'));
        $this->assertSame(0, DeliveryKudos::query()->count() + DeliveryComment::query()->count());
    }

    public function test_only_people_who_can_see_the_job_can_react(): void
    {
        $hidden = $this->job(job: ['hidden' => true]);
        $private = $this->job(driver: ['profile_private' => true]);
        $stranger = User::factory()->create();

        foreach ([$hidden, $private] as $job) {
            $this->actingAs($stranger)->post(route('jobs.kudos.give', $job))->assertNotFound();
            $this->actingAs($stranger)->post(route('jobs.comments.store', $job), ['body' => 'Hi'])->assertNotFound();
        }

        // A company mate sees the private driver's job, so they can react to it.
        $company = Company::factory()->create();
        $company->members()->create(['user_id' => $private->user_id, 'role' => CompanyRole::Owner]);
        $mate = User::factory()->create();
        $company->members()->create(['user_id' => $mate->id, 'role' => CompanyRole::Driver]);
        $this->actingAs($mate)->post(route('jobs.kudos.give', $private))->assertRedirect();
        $this->actingAs($mate)->post(route('jobs.comments.store', $private), ['body' => 'Good one, boss'])->assertRedirect();

        // The driver can comment on their own hidden job.
        $this->actingAs($hidden->user)->post(route('jobs.comments.store', $hidden), ['body' => 'Note to self'])->assertRedirect();

        $this->assertSame(1, DeliveryKudos::query()->count());
        $this->assertSame(2, DeliveryComment::query()->count());
    }

    public function test_comments_are_plain_text_up_to_1000_characters(): void
    {
        $job = $this->job();
        $fan = User::factory()->create();

        $this->actingAs($fan)->post(route('jobs.comments.store', $job), ['body' => '   '])->assertSessionHasErrors('body');
        $this->actingAs($fan)->post(route('jobs.comments.store', $job), ['body' => str_repeat('a', 1001)])->assertSessionHasErrors('body');
        $this->actingAs($fan)->post(route('jobs.comments.store', $job), ['body' => str_repeat('a', 1000)])->assertSessionHasNoErrors();
        $this->actingAs($fan)->post(route('jobs.comments.store', $job), ['body' => "<b>Hi</b> see https://example.com\r\nline two"]);

        $this->asGuest()->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page
            ->has('comments', 2)
            ->where('comments.1.body', "<b>Hi</b> see https://example.com\nline two")
            ->where('comments.1.author.id', $fan->id)
            ->where('comments.1.can_delete', false)
            ->missing('comments.1.author.email'));
    }

    public function test_the_author_and_the_jobs_driver_can_delete_a_comment_nobody_else(): void
    {
        $job = $this->job();
        $author = User::factory()->create();
        $other = User::factory()->create();
        $first = $job->comments()->create(['user_id' => $author->id, 'body' => 'One']);
        $second = $job->comments()->create(['user_id' => $author->id, 'body' => 'Two']);

        $this->actingAs($other)->delete(route('jobs.comments.destroy', [$job, $first]))->assertForbidden();
        $this->actingAs($author)->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page->where('comments.0.can_delete', true));
        $this->actingAs($job->user)->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page->where('comments.0.can_delete', true));

        $this->actingAs($author)->delete(route('jobs.comments.destroy', [$job, $first]))->assertRedirect();
        $this->actingAs($job->user)->delete(route('jobs.comments.destroy', [$job, $second]))->assertRedirect();
        $this->assertSame(0, DeliveryComment::query()->count());

        // A comment id from another job doesn't match.
        $elsewhere = $this->job()->comments()->create(['user_id' => $author->id, 'body' => 'Three']);
        $this->actingAs($author)->delete(route('jobs.comments.destroy', [$job, $elsewhere]))->assertNotFound();
    }

    public function test_comments_are_throttled_to_ten_a_minute(): void
    {
        $job = $this->job();
        $fan = User::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($fan)->post(route('jobs.comments.store', $job), ['body' => "Comment {$i}"])->assertRedirect();
        }
        $this->actingAs($fan)->post(route('jobs.comments.store', $job), ['body' => 'One too many'])->assertStatus(429);
    }

    public function test_the_jobs_driver_hears_about_kudos_once_and_about_comments(): void
    {
        Notification::fake();
        $job = $this->job();
        $fan = User::factory()->create(['name' => 'Fran Fan']);

        $this->actingAs($fan)->post(route('jobs.comments.store', $job), ['body' => 'Smooth parking!']);
        Notification::assertSentTo($job->user, HubNotice::class, fn (HubNotice $n) => $n->notice === NoticeType::CommentReceived
            && $n->message === 'Fran Fan commented on your job Calais → Paris: “Smooth parking!”'
            && $n->url === route('jobs.show', $job).'#comments'
            && $n->via($job->user) === ['database']);

        // The driver's own comments and kudos attempts ring nothing.
        $this->actingAs($job->user)->post(route('jobs.comments.store', $job), ['body' => 'Thanks!']);
        Notification::assertSentToTimes($job->user, HubNotice::class, 1);
    }

    public function test_taking_kudos_back_and_giving_it_again_rings_once(): void
    {
        $job = $this->job();
        $fan = User::factory()->create(['name' => 'Fran Fan']);

        $this->actingAs($fan)->post(route('jobs.kudos.give', $job));
        $this->actingAs($fan)->delete(route('jobs.kudos.take', $job));
        $this->actingAs($fan)->post(route('jobs.kudos.give', $job));

        $notices = $job->user->notifications()->get();
        $this->assertCount(1, $notices);
        $this->assertSame(NoticeType::KudosReceived->value, $notices[0]->data['type']);
        $this->assertSame('Fran Fan gave kudos to your job Calais → Paris.', $notices[0]->data['message']);
    }

    public function test_suspended_drivers_kudos_and_comments_are_left_out(): void
    {
        $job = $this->job();
        $troll = User::factory()->create();
        DeliveryKudos::query()->create(['delivery_id' => $job->id, 'user_id' => $troll->id]);
        $job->comments()->create(['user_id' => $troll->id, 'body' => 'spam']);
        $troll->forceFill(['suspended_at' => now(), 'suspension_reason' => 'Spam'])->save();

        $this->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page
            ->where('job.kudos', 0)
            ->where('job.comments', 0)
            ->has('comments', 0));
    }

    public function test_deleting_a_job_or_an_account_removes_its_reactions_and_the_export_lists_them(): void
    {
        $job = $this->job();
        $fan = User::factory()->create();
        DeliveryKudos::query()->create(['delivery_id' => $job->id, 'user_id' => $fan->id]);
        $job->comments()->create(['user_id' => $fan->id, 'body' => 'Nice run']);

        $export = PrivacyController::exportFor($fan);
        $this->assertSame(route('jobs.show', $job), $export['kudos_given'][0]['job']);
        $this->assertSame('Nice run', $export['comments'][0]['body']);

        $fan->delete();
        $this->assertSame(0, DeliveryKudos::query()->count() + DeliveryComment::query()->count());

        $other = User::factory()->create();
        $job->comments()->create(['user_id' => $other->id, 'body' => 'Still here?']);
        $job->delete();
        $this->assertSame(0, DeliveryComment::query()->count());
    }
}
