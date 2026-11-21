<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\NoticeType;
use App\Http\Controllers\Settings\PrivacyController;
use App\Jobs\PostToDiscord;
use App\Models\Company;
use App\Models\CompanyPost;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Company news (#5224). */
class CompanyNewsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Company, User, User, User} company, owner, manager, driver */
    private function company(): array
    {
        $company = Company::factory()->create(['name' => 'News Haulage', 'tag' => 'NWS']);
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
        $company->members()->create(['user_id' => $manager->id, 'role' => CompanyRole::Manager]);
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);

        return [$company, $owner, $manager, $driver];
    }

    /** @param array<string, mixed> $overrides */
    private function newsPost(Company $company, ?User $author, array $overrides = []): CompanyPost
    {
        $post = new CompanyPost(['title' => 'Hello drivers', 'body' => 'Line one', 'visibility' => 'members', ...$overrides]);
        $post->company_id = $company->id;
        $post->created_by = $author?->id;
        $post->save();

        return $post;
    }

    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** @return list<string> the titles in the company page's `news` prop for whoever is signed in */
    private function newsTitles(Company $company): array
    {
        $titles = [];
        $this->get(route('companies.show', $company))->assertOk()
            ->assertInertia(function ($page) use (&$titles) {
                $titles = array_column($page->toArray()['props']['news'], 'title');

                return $page;
            });

        return $titles;
    }

    public function test_owners_and_managers_post_drivers_and_outsiders_cannot(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();
        $form = ['title' => 'Convoy on Friday', 'body' => "Meet at Calais.\nhttps://truckersmp.com/events/1", 'visibility' => 'public'];

        $this->actingAs($driver)->get(route('companies.news.create', $company))->assertForbidden();
        $this->actingAs($driver)->post(route('companies.news.store', $company), $form)->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('companies.news.store', $company), $form)->assertForbidden();
        $this->asGuest()->post(route('companies.news.store', $company), $form)->assertRedirect(route('login'));

        $this->actingAs($owner)->get(route('companies.news.create', $company))->assertOk()
            ->assertInertia(fn ($page) => $page->component('news/Edit')->where('post', null)->where('discord', false));
        $this->actingAs($manager)->post(route('companies.news.store', $company), $form)
            ->assertRedirect(route('companies.show', $company));

        $post = CompanyPost::query()->sole();
        $this->assertSame('Convoy on Friday', $post->title);
        $this->assertSame("Meet at Calais.\nhttps://truckersmp.com/events/1", $post->body);
        $this->assertSame('public', $post->visibility);
        $this->assertSame($manager->id, $post->created_by);
        $this->assertNull($post->pinned_at);
    }

    public function test_the_form_is_checked(): void
    {
        [$company, $owner] = $this->company();

        $this->actingAs($owner)->post(route('companies.news.store', $company), [
            'title' => str_repeat('a', CompanyPost::TITLE_MAX + 1),
            'body' => '',
            'visibility' => 'everyone',
        ])->assertSessionHasErrors(['title', 'body', 'visibility']);

        $this->actingAs($owner)->post(route('companies.news.store', $company), [
            'title' => 'Ok', 'body' => str_repeat('b', CompanyPost::BODY_MAX + 1), 'visibility' => 'members',
        ])->assertSessionHasErrors(['body']);

        $this->assertDatabaseCount('company_posts', 0);
    }

    public function test_a_new_post_rings_every_other_members_bell_unless_switched_off(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();
        $quiet = User::factory()->create(['bell_opt_outs' => [NoticeType::CompanyNews->value]]);
        $company->members()->create(['user_id' => $quiet->id, 'role' => CompanyRole::Driver]);
        $suspended = User::factory()->create(['suspended_at' => now()]);
        $company->members()->create(['user_id' => $suspended->id, 'role' => CompanyRole::Driver]);
        $outsider = User::factory()->create();

        $this->actingAs($owner)->post(route('companies.news.store', $company), [
            'title' => 'Server move', 'body' => 'We drive on Sim 2 now.', 'visibility' => 'members',
        ])->assertRedirect();

        foreach ([$manager, $driver] as $member) {
            $notice = $member->notifications()->sole();
            $this->assertSame('company_news', $notice->data['type']);
            $this->assertSame('News Haulage posted news: “Server move”', $notice->data['message']);
            $this->assertSame(route('companies.show', $company), $notice->data['url']);
        }
        foreach ([$owner, $quiet, $suspended, $outsider] as $nobody) {
            $this->assertSame(0, $nobody->notifications()->count());
        }

        // Editing doesn't ring again.
        $post = CompanyPost::query()->sole();
        $this->actingAs($owner)->put(route('news.update', $post), [
            'title' => 'Server move (updated)', 'body' => 'Sim 2.', 'visibility' => 'members',
        ])->assertRedirect(route('companies.show', $company));
        $this->assertSame(1, $driver->notifications()->count());
        $this->assertSame('Server move (updated)', $post->refresh()->title);
    }

    public function test_guests_and_outsiders_see_public_posts_members_see_all(): void
    {
        [$company, $owner, , $driver] = $this->company();
        $this->newsPost($company, $owner, ['title' => 'Public news', 'visibility' => 'public']);
        $this->travel(1)->minutes();
        $this->newsPost($company, $owner, ['title' => 'Members news', 'visibility' => 'members']);
        $other = Company::factory()->create();
        $this->newsPost($other, null, ['title' => 'Other company news', 'visibility' => 'public']);

        $this->assertSame(['Public news'], $this->asGuest()->newsTitles($company));
        $this->assertSame(['Public news'], $this->actingAs(User::factory()->create())->newsTitles($company));
        $this->assertSame(['Members news', 'Public news'], $this->actingAs($driver)->newsTitles($company));

        // A member of another company is an outsider here.
        $otherMember = User::factory()->create();
        $other->members()->create(['user_id' => $otherMember->id, 'role' => CompanyRole::Owner]);
        $this->assertSame(['Public news'], $this->actingAs($otherMember)->newsTitles($company));

        // A suspended member only gets what the public gets.
        $driver->forceFill(['suspended_at' => now()])->save();
        $this->assertSame(['Public news'], CompanyPost::query()->where('company_id', $company->id)
            ->visibleTo($driver->refresh())->pluck('title')->all());
    }

    public function test_private_authors_show_as_private_driver_to_outsiders(): void
    {
        [$company, $owner] = $this->company();
        $owner->forceFill(['profile_private' => true, 'name' => 'Olive Owner'])->save();
        $this->newsPost($company, $owner, ['visibility' => 'public']);

        $this->asGuest()->get(route('companies.show', $company))->assertOk()
            ->assertInertia(fn ($page) => $page->where('news.0.author.name', 'Private driver')->where('news.0.author.username', null));
        $this->actingAs($owner)->get(route('companies.show', $company))->assertOk()
            ->assertInertia(fn ($page) => $page->where('news.0.author.name', 'Olive Owner'));
    }

    public function test_one_post_is_pinned_and_it_comes_first(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();
        $first = $this->newsPost($company, $owner, ['title' => 'First']);
        $this->travel(1)->minutes();
        $second = $this->newsPost($company, $owner, ['title' => 'Second']);
        $this->travel(1)->minutes();
        $this->newsPost($company, $owner, ['title' => 'Third']);

        $this->actingAs($driver)->put(route('news.pin', $first), ['pinned' => true])->assertForbidden();

        $this->actingAs($manager)->put(route('news.pin', $first), ['pinned' => true])->assertRedirect();
        $this->assertSame(['First', 'Third', 'Second'], $this->actingAs($driver)->newsTitles($company));

        // Pinning another unpins the first; pinning isn't an edit.
        $this->travel(5)->minutes();
        $this->actingAs($owner)->put(route('news.pin', $second), ['pinned' => true])->assertRedirect();
        $this->assertNull($first->refresh()->pinned_at);
        $this->assertSame(1, CompanyPost::query()->whereNotNull('pinned_at')->count());
        $this->assertSame(['Second', 'Third', 'First'], $this->actingAs($driver)->newsTitles($company));
        $this->actingAs($driver)->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page->where('news.0.pinned', true)->where('news.0.edited', false));

        $this->actingAs($owner)->put(route('news.pin', $second), ['pinned' => false])->assertRedirect();
        $this->assertSame(0, CompanyPost::query()->whereNotNull('pinned_at')->count());
        $this->assertSame(['Third', 'Second', 'First'], $this->actingAs($driver)->newsTitles($company));
    }

    public function test_only_this_companys_owners_and_managers_edit_and_delete(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();
        $post = $this->newsPost($company, $owner);
        $form = ['title' => 'Changed', 'body' => 'Changed', 'visibility' => 'public'];
        $otherOwner = User::factory()->create();
        Company::factory()->create()->members()->create(['user_id' => $otherOwner->id, 'role' => CompanyRole::Owner]);

        foreach ([$driver, $otherOwner] as $who) {
            $this->actingAs($who)->get(route('news.edit', $post))->assertForbidden();
            $this->actingAs($who)->put(route('news.update', $post), $form)->assertForbidden();
            $this->actingAs($who)->delete(route('news.destroy', $post))->assertForbidden();
        }
        $this->assertSame('Hello drivers', $post->refresh()->title);

        // A manager can edit the owner's post (the company's news, not one person's).
        $this->actingAs($manager)->get(route('news.edit', $post))->assertOk()
            ->assertInertia(fn ($page) => $page->component('news/Edit')->where('post.title', 'Hello drivers'));
        $this->actingAs($manager)->put(route('news.update', $post), $form)->assertRedirect();
        $this->assertSame('public', $post->refresh()->visibility);

        $this->actingAs($manager)->delete(route('news.destroy', $post))->assertRedirect();
        $this->assertDatabaseCount('company_posts', 0);
    }

    public function test_also_post_to_discord_queues_a_post_only_when_ticked_and_the_webhook_is_on(): void
    {
        Queue::fake([PostToDiscord::class]);
        [$company, $owner] = $this->company();
        $form = ['title' => 'Discord news', 'body' => 'Hello Discord', 'visibility' => 'members'];

        // No webhook: nothing to post to.
        $this->actingAs($owner)->post(route('companies.news.store', $company), [...$form, 'discord' => '1']);
        Queue::assertNothingPushed();

        $company->forceFill(['discord_webhook' => 'https://discord.com/api/webhooks/123456/'.str_repeat('a', 30)])->save();
        $this->actingAs($owner)->get(route('companies.news.create', $company))
            ->assertInertia(fn ($page) => $page->where('discord', true));

        $this->actingAs($owner)->post(route('companies.news.store', $company), $form);
        Queue::assertNothingPushed();

        $this->actingAs($owner)->post(route('companies.news.store', $company), [...$form, 'discord' => '1']);
        Queue::assertPushed(PostToDiscord::class, function (PostToDiscord $job) use ($company) {
            $embed = $job->payload['embeds'][0];

            return $job->companyId === $company->id
                && $embed['title'] === 'Discord news'
                && $embed['description'] === 'Hello Discord'
                && $embed['url'] === route('companies.show', $company)
                && $job->payload['allowed_mentions'] === ['parse' => []];
        });
        Queue::assertPushed(PostToDiscord::class, 1);
    }

    public function test_the_home_page_my_company_tab_shows_the_news(): void
    {
        [$company, $owner, , $driver] = $this->company();
        $this->newsPost($company, $owner, ['title' => 'Members news']);
        $this->newsPost(Company::factory()->create(), null, ['title' => 'Elsewhere', 'visibility' => 'public']);

        $this->actingAs($driver)->get(route('home', ['feed' => 'company']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('news', 1)
                ->where('news.0.title', 'Members news')
                ->where('news.0.company.tag', 'NWS'));
        $this->actingAs($driver)->get(route('home', ['feed' => 'everyone']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('news', []));
        $this->asGuest()->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page->where('news', []));
    }

    public function test_the_bell_switch_in_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('notification-settings.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('bell', 1)
                ->where('bell.0.type', 'company_news')
                ->where('bell.0.label', 'News from my company')
                ->where('bell.0.enabled', true));

        $this->actingAs($user)->put(route('notification-settings.update'), ['enabled' => [], 'bell' => []])
            ->assertRedirect(route('notification-settings.edit'));
        $user->refresh();
        $this->assertSame(['company_news'], $user->bell_opt_outs);
        $this->assertFalse($user->wantsBell(NoticeType::CompanyNews));
        // Other notices can't be switched off.
        $this->assertTrue($user->wantsBell(NoticeType::KudosReceived));

        // A request without `bell` (an older page) leaves it alone.
        $this->actingAs($user)->put(route('notification-settings.update'), ['enabled' => []]);
        $this->assertSame(['company_news'], $user->refresh()->bell_opt_outs);

        $this->actingAs($user)->put(route('notification-settings.update'), ['enabled' => [], 'bell' => ['company_news']]);
        $this->assertSame([], $user->refresh()->bell_opt_outs);

        $this->actingAs($user)->put(route('notification-settings.update'), ['enabled' => [], 'bell' => ['kudos_received']])
            ->assertSessionHasErrors('bell.0');
    }

    public function test_export_deletion_and_the_notice_itself(): void
    {
        [$company, $owner, , $driver] = $this->company();
        $post = $this->newsPost($company, $owner, ['title' => 'Exported', 'body' => 'Body text']);

        $export = PrivacyController::exportFor($owner);
        $this->assertSame('Exported', $export['news_posts'][0]['title']);
        $this->assertSame('News Haulage', $export['news_posts'][0]['company']);
        $this->assertSame([], $export['bell_notifications_off']);
        $this->assertSame([], PrivacyController::exportFor($driver)['news_posts']);

        $this->assertSame(['database'], HubNotice::companyNews($post)->via($driver));

        // The author leaving the Hub keeps the company's post, without an author.
        $owner->delete();
        $this->assertNull($post->refresh()->created_by);
        $this->actingAs($driver)->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page->where('news.0.author', null));

        $company->delete();
        $this->assertDatabaseCount('company_posts', 0);
    }
}
