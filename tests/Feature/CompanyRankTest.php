<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\DeliveryStatus;
use App\Enums\NoticeType;
use App\Enums\Recruitment;
use App\Jobs\PostToDiscord;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\CompanyRank;
use App\Models\Delivery;
use App\Models\User;
use App\Support\CompanyRanks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\JobSyncTest;
use Tests\TestCase;

/** Company ranks by distance driven for the company (#5223). */
class CompanyRankTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEF-wxyz';

    /** @return array{0: Company, 1: User, 2: User, 3: User} company, owner, manager, driver */
    private function company(): array
    {
        $company = Company::factory()->create(['name' => 'Rank Haulage', 'tag' => 'RNK', 'recruitment' => Recruitment::Open]);
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $driver = User::factory()->create(['name' => 'Dora Driver']);
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
        $company->members()->create(['user_id' => $manager->id, 'role' => CompanyRole::Manager]);
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);

        return [$company->refresh(), $owner, $manager, $driver];
    }

    /** @return list<array<string, mixed>> */
    private function ladder(): array
    {
        return [
            ['id' => null, 'name' => 'Rookie', 'color' => '#64748B', 'min_km' => 0],
            ['id' => null, 'name' => 'Driver', 'color' => '#22c55e', 'min_km' => 500],
            ['id' => null, 'name' => 'Senior', 'color' => '#0ea5e9', 'min_km' => 2000],
        ];
    }

    private function rank(Company $company, string $name): CompanyRank
    {
        return CompanyRank::query()->where('company_id', $company->id)->where('name', $name)->firstOrFail();
    }

    private function rankOf(User $user): ?string
    {
        return CompanyMember::query()->where('user_id', $user->id)->first()?->rank?->name;
    }

    private function job(User $user, Company $company, float $km, array $state = []): Delivery
    {
        return Delivery::factory()->for($user)->create([
            'company_id' => $company->id,
            'distance_km' => $km,
            'status' => DeliveryStatus::Delivered,
            ...$state,
        ]);
    }

    private function sync(User $driver, array $jobs): void
    {
        $token = $driver->createToken('PT', ['sync'])->plainTextToken;
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => $jobs])->assertOk();
    }

    /** @return array<string, mixed> a recent, plausible job for the sync API (291.4 km) */
    private function recentJob(string $id): array
    {
        return JobSyncTest::job([
            'id' => $id,
            'started_at' => now()->subHours(5)->toIso8601String(),
            'completed_at' => now()->subHour()->toIso8601String(),
        ]);
    }

    public function test_owners_and_managers_save_ranks_and_members_get_the_highest_rank_their_distance_reaches(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();
        $this->job($driver, $company, 600);
        $this->job($manager, $company, 2500);

        $this->actingAs($manager)->put(route('companies.ranks.update', $company), ['ranks' => $this->ladder()])
            ->assertRedirect(route('companies.edit', $company));

        $this->assertSame(['Rookie', 'Driver', 'Senior'], $company->ranks()->pluck('name')->all());
        $this->assertSame('#64748b', $this->rank($company, 'Rookie')->color);
        $this->assertSame('Rookie', $this->rankOf($owner));
        $this->assertSame('Driver', $this->rankOf($driver));
        $this->assertSame('Senior', $this->rankOf($manager));
        // Setting up ranks rings nobody's bell.
        $this->assertSame(0, $driver->notifications()->count());

        $this->actingAs($driver)->put(route('companies.ranks.update', $company), ['ranks' => []])->assertForbidden();
        $this->actingAs(User::factory()->create())->put(route('companies.ranks.update', $company), ['ranks' => []])->assertForbidden();
    }

    public function test_only_counted_jobs_driven_for_this_company_count(): void
    {
        [$company, , , $driver] = $this->company();
        $other = Company::factory()->create();
        $this->job($driver, $company, 400);
        $this->job($driver, $other, 5000);
        $this->job($driver, $company, 5000, ['hidden' => true]);
        $this->job($driver, $company, 5000, ['flagged' => true]);
        $this->job($driver, $company, 5000, ['status' => DeliveryStatus::Cancelled]);
        $this->job($driver, $company, 5000, ['hub_flag_reason' => 'speed']);

        CompanyRanks::save($company, ['ranks' => $this->ladder()]);

        $this->assertSame('Rookie', $this->rankOf($driver));
        $this->job($driver, $company, 100, ['status' => DeliveryStatus::Late]);
        CompanyRanks::refreshUser($driver, notify: false);
        $this->assertSame('Driver', $this->rankOf($driver));
    }

    public function test_thresholds_are_inclusive_and_no_rank_below_the_lowest_threshold(): void
    {
        [$company, , , $driver] = $this->company();
        CompanyRanks::save($company, ['ranks' => [
            ['name' => 'Driver', 'color' => '#22c55e', 'min_km' => 500],
            ['name' => 'Senior', 'color' => '#0ea5e9', 'min_km' => 2000],
        ]]);
        $this->assertNull($this->rankOf($driver));

        $this->job($driver, $company, 499.9);
        CompanyRanks::refreshUser($driver, notify: false);
        $this->assertNull($this->rankOf($driver));

        $this->job($driver, $company, 0.1);
        CompanyRanks::refreshUser($driver, notify: false);
        $this->assertSame('Driver', $this->rankOf($driver));
    }

    public function test_bad_lists_are_refused(): void
    {
        [$company, $owner] = $this->company();
        $this->actingAs($owner);

        $tooMany = array_map(fn ($i) => ['name' => "R$i", 'color' => '#000000', 'min_km' => $i * 10], range(1, 13));
        $this->put(route('companies.ranks.update', $company), ['ranks' => $tooMany])->assertSessionHasErrors('ranks');
        $this->put(route('companies.ranks.update', $company), ['ranks' => [
            ['name' => 'A', 'color' => '#000000', 'min_km' => 100],
            ['name' => 'B', 'color' => '#000000', 'min_km' => 100],
        ]])->assertSessionHasErrors('ranks.1.min_km');
        $this->put(route('companies.ranks.update', $company), ['ranks' => [
            ['name' => 'Same', 'color' => '#000000', 'min_km' => 0],
            ['name' => 'same', 'color' => '#000000', 'min_km' => 10],
        ]])->assertSessionHasErrors('ranks.1.name');
        $this->put(route('companies.ranks.update', $company), ['ranks' => [
            ['name' => 'X', 'color' => 'red', 'min_km' => 0],
        ]])->assertSessionHasErrors('ranks.0.color');
        $this->put(route('companies.ranks.update', $company), ['ranks' => [
            ['name' => 'X', 'color' => '#000000', 'min_km' => -1],
        ]])->assertSessionHasErrors('ranks.0.min_km');

        $this->assertSame(0, $company->ranks()->count());
    }

    public function test_a_sync_past_a_threshold_promotes_with_a_bell_notice_and_a_discord_post(): void
    {
        // Only the Discord job: a full fake would also swallow the queued bell notice.
        Queue::fake([PostToDiscord::class]);
        [$company, , , $driver] = $this->company();
        $company->forceFill(['discord_webhook' => self::WEBHOOK])->save();
        CompanyRanks::save($company, ['ranks' => $this->ladder()]);
        $this->assertSame('Rookie', $this->rankOf($driver));

        // 291.4 km: still a rookie, nothing announced.
        $this->sync($driver, [$this->recentJob('a1b2c3d4-0000-4000-8000-000000000001')]);
        $this->assertSame('Rookie', $this->rankOf($driver));
        $this->assertSame(0, $driver->notifications()->where('data->type', NoticeType::RankPromoted->value)->count());

        // 582.8 km: Driver.
        $this->sync($driver, [$this->recentJob('a1b2c3d4-0000-4000-8000-000000000002')]);
        $this->assertSame('Driver', $this->rankOf($driver));

        $notice = $driver->notifications()->where('data->type', NoticeType::RankPromoted->value)->sole();
        $this->assertSame('You were promoted to Driver at Rank Haulage.', $notice->data['message']);
        Queue::assertPushed(PostToDiscord::class, fn (PostToDiscord $job) => $job->payload['embeds'][0]['title'] === 'Dora Driver is now Driver');
    }

    public function test_a_pin_is_a_floor_that_driving_can_still_pass(): void
    {
        [$company, $owner, , $driver] = $this->company();
        CompanyRanks::save($company, ['ranks' => $this->ladder()]);
        $member = CompanyMember::query()->where('user_id', $driver->id)->firstOrFail();

        $this->actingAs($owner)->put(route('companies.members.rank', [$company, $member]), [
            'rank_id' => $this->rank($company, 'Driver')->id,
        ])->assertRedirect();

        $this->assertSame('Driver', $this->rankOf($driver));
        $this->assertSame(1, $driver->notifications()->where('data->type', NoticeType::RankPromoted->value)->count());

        // A rank-change elsewhere (job deleted, ranks edited) never drops below the pin.
        CompanyRanks::save($company, ['ranks' => $company->ranks()->get()->map(fn (CompanyRank $r) => $r->summary())->all()]);
        $this->assertSame('Driver', $this->rankOf($driver));

        // Driving past a higher rank still promotes.
        $this->job($driver, $company, 2100);
        CompanyRanks::refreshUser($driver, notify: false);
        $this->assertSame('Senior', $this->rankOf($driver));

        // Clearing the pin: the distance decides again.
        Delivery::query()->where('user_id', $driver->id)->delete();
        $this->actingAs($owner)->put(route('companies.members.rank', [$company, $member]), ['rank_id' => null])->assertRedirect();
        $this->assertSame('Rookie', $this->rankOf($driver));
    }

    public function test_who_may_pin(): void
    {
        [$company, $owner, $manager, $driver] = $this->company();
        $other = Company::factory()->create();
        CompanyRanks::save($company, ['ranks' => $this->ladder()]);
        $senior = $this->rank($company, 'Senior')->id;
        $members = CompanyMember::query()->where('company_id', $company->id)->get()->keyBy('user_id');

        // A manager pins drivers and themselves, not the owner.
        $this->actingAs($manager)->put(route('companies.members.rank', [$company, $members[$driver->id]]), ['rank_id' => $senior])->assertRedirect();
        $this->actingAs($manager)->put(route('companies.members.rank', [$company, $members[$manager->id]]), ['rank_id' => $senior])->assertRedirect();
        $this->actingAs($manager)->put(route('companies.members.rank', [$company, $members[$owner->id]]), ['rank_id' => $senior])->assertForbidden();
        // Drivers can't pin, not even themselves.
        $this->actingAs($driver)->put(route('companies.members.rank', [$company, $members[$driver->id]]), ['rank_id' => null])->assertForbidden();
        // A member of another company, and another company's rank.
        $this->actingAs($owner)->put(route('companies.members.rank', [$other, $members[$driver->id]]), ['rank_id' => null])->assertNotFound();
        $foreign = $other->ranks()->create(['name' => 'X', 'color' => '#000000', 'min_km' => 0]);
        $this->actingAs($owner)->put(route('companies.members.rank', [$company, $members[$driver->id]]), ['rank_id' => $foreign->id])->assertStatus(422);

        $this->assertSame('Senior', $this->rankOf($driver));
        $this->assertSame('Senior', $this->rankOf($manager));
        $this->assertSame('Rookie', $this->rankOf($owner));
    }

    public function test_editing_the_list_keeps_ids_and_pins_and_removing_a_rank_clears_it(): void
    {
        [$company, , , $driver] = $this->company();
        CompanyRanks::save($company, ['ranks' => $this->ladder()]);
        $member = CompanyMember::query()->where('user_id', $driver->id)->firstOrFail();
        $driverRank = $this->rank($company, 'Driver');
        CompanyRanks::pin($member, $driverRank);

        // Rename and lower "Driver": same row, the pin stays.
        CompanyRanks::save($company, ['ranks' => [
            ['id' => $this->rank($company, 'Rookie')->id, 'name' => 'Rookie', 'color' => '#64748b', 'min_km' => 0],
            ['id' => $driverRank->id, 'name' => 'Trucker', 'color' => '#22c55e', 'min_km' => 300],
        ]]);
        $this->assertSame($driverRank->id, $this->rank($company, 'Trucker')->id);
        $this->assertSame('Trucker', $this->rankOf($driver));
        $this->assertSame($driverRank->id, $member->refresh()->pinned_rank_id);
        $this->assertSame(2, $company->ranks()->count());

        // Remove it: the pin goes, the distance decides.
        CompanyRanks::save($company, ['ranks' => [
            ['id' => $this->rank($company, 'Rookie')->id, 'name' => 'Rookie', 'color' => '#64748b', 'min_km' => 0],
        ]]);
        $this->assertNull($member->refresh()->pinned_rank_id);
        $this->assertSame('Rookie', $this->rankOf($driver));

        // No ranks at all: nobody has one.
        CompanyRanks::save($company, ['ranks' => []]);
        $this->assertNull($this->rankOf($driver));
    }

    public function test_joining_gets_a_rank_silently_and_deleting_a_job_can_lower_it(): void
    {
        [$company] = $this->company();
        CompanyRanks::save($company, ['ranks' => $this->ladder()]);
        $newcomer = User::factory()->create();

        $this->actingAs($newcomer)->post(route('companies.join', $company))->assertRedirect();
        $this->assertSame('Rookie', $this->rankOf($newcomer));

        $job = $this->job($newcomer, $company, 800);
        CompanyRanks::refreshUser($newcomer, notify: false);
        $this->assertSame('Driver', $this->rankOf($newcomer));

        $token = $newcomer->createToken('PT', ['sync'])->plainTextToken;
        $this->withToken($token)->deleteJson(route('api.jobs.destroy', $job->client_id))->assertNoContent();
        $this->assertSame('Rookie', $this->rankOf($newcomer));
        $this->assertSame(0, $newcomer->notifications()->where('data->type', NoticeType::RankPromoted->value)->count());
    }

    public function test_pages_show_the_rank_and_only_managers_see_pins(): void
    {
        [$company, $owner, , $driver] = $this->company();
        $driver->forceFill(['username' => 'dora-driver'])->save();
        CompanyRanks::save($company, ['ranks' => $this->ladder()]);
        $member = CompanyMember::query()->where('user_id', $driver->id)->firstOrFail();
        CompanyRanks::pin($member, $this->rank($company, 'Senior'));

        $this->actingAs($owner)->get(route('companies.show', $company))->assertInertia(fn (Assert $page) => $page
            ->where('ranks.2.name', 'Senior')
            ->where('members', fn ($members) => collect($members)->contains(fn ($m) => $m['rank']['name'] === 'Senior' && $m['pinned_rank_id'] === $member->pinned_rank_id))
        );

        auth()->logout();
        $this->get(route('companies.show', $company))->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->every(fn ($m) => $m['pinned_rank_id'] === null)
                && collect($members)->contains(fn ($m) => ($m['rank']['name'] ?? null) === 'Senior'))
        );

        $this->get(route('drivers.show', $driver))->assertInertia(fn (Assert $page) => $page
            ->where('driver.company_rank.name', 'Senior')
            ->where('driver.company_rank.color', '#0ea5e9')
        );

        $this->actingAs($owner)->get(route('companies.edit', $company))->assertInertia(fn (Assert $page) => $page
            ->has('ranks', 3)
            ->where('ranks.0.name', 'Rookie')
        );
    }
}
