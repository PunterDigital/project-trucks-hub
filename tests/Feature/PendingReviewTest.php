<?php

namespace Tests\Feature;

use App\Admin\Moderation;
use App\Enums\CompanyRole;
use App\Filament\Resources\Deliveries\DeliveryResource;
use App\Filament\Resources\Deliveries\Pages\ListDeliveries;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use App\Support\Leaderboard;
use App\Support\Period;
use App\Support\Stats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Flagged jobs wait for a staff review before anyone else sees them or they count (#4743). */
class PendingReviewTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    /** @return array{0: User, 1: User, 2: Delivery} driver, company mate, flagged job */
    private function flaggedJob(array $attributes = ['flagged' => true, 'flag_reason' => 'Teleported']): array
    {
        $driver = User::factory()->create();
        $mate = User::factory()->create();
        $company = Company::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);
        $company->members()->create(['user_id' => $mate->id, 'role' => CompanyRole::Driver]);
        $job = Delivery::factory()->for($driver)->create(['company_id' => $company->id, ...$attributes]);

        return [$driver, $mate, $job];
    }

    public function test_a_flagged_job_shows_only_to_its_driver_and_counts_nowhere(): void
    {
        foreach ([['flagged' => true, 'flag_reason' => 'Teleported'], ['hub_flag_reason' => 'Average speed over 150 km/h']] as $flags) {
            [$driver, $mate, $job] = $this->flaggedJob($flags);

            $this->assertSame(Delivery::REVIEW_PENDING, $job->reviewState());
            $this->assertFalse(Delivery::query()->counted()->whereKey($job->id)->exists());

            // Guest, a company mate and another driver: no job page, not on the profile, not in feeds.
            $this->get(route('jobs.show', $job))->assertNotFound();
            $this->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page->has('jobs.data', 0));
            $this->get(route('home'))->assertInertia(fn ($page) => $page->has('latest', 0));
            $this->actingAs($mate)->get(route('jobs.show', $job))->assertNotFound();
            $this->actingAs($mate)->get(route('companies.show', $job->company))
                ->assertInertia(fn ($page) => $page->has('latest', 0));

            // The driver sees it, marked pending, with the reason.
            $this->actingAs($driver)->get(route('jobs.show', $job))->assertOk()->assertInertia(fn ($page) => $page
                ->where('job.review', 'pending')
                ->where('job.review_reasons', [$flags['flag_reason'] ?? $flags['hub_flag_reason']])
                ->where('job.review_time', Delivery::REVIEW_TIME));
            $this->actingAs($driver)->get(route('drivers.show', $driver))->assertInertia(fn ($page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.review', 'pending'));

            $job->delete();
            auth()->logout();
        }
    }

    public function test_approving_makes_the_job_public_and_counted_even_with_the_apps_flag(): void
    {
        [$driver, , $job] = $this->flaggedJob(['flagged' => true, 'flag_reason' => 'Teleported', 'distance_km' => 1600]);

        Moderation::approveJob($job, 'Checked the replay', $this->staff());

        $job->refresh();
        $this->assertSame(Delivery::REVIEW_APPROVED, $job->reviewState());
        $this->assertTrue($job->flagged, 'the app’s flag is kept as a record');
        $this->get(route('jobs.show', $job))->assertOk()->assertInertia(fn ($page) => $page->where('job.review', 'approved'));
        $this->assertEqualsWithDelta(1600.0, Stats::totals(Delivery::query())['distance_km'], 0.01);
        $this->assertSame($driver->username, Leaderboard::drivers(Period::All, 'distance')[0]['subject']['username'] ?? null);
        $this->assertSame(2, $driver->refresh()->level, 'levels follow the approval');
    }

    public function test_a_rejected_job_stays_hidden_and_the_driver_sees_why(): void
    {
        [$driver, $mate, $job] = $this->flaggedJob();

        Moderation::rejectJob($job, 'Speed hack in the replay', $this->staff());

        $this->actingAs($mate)->get(route('jobs.show', $job))->assertNotFound();
        $this->assertFalse(Delivery::query()->counted()->exists());
        $this->actingAs($driver)->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page
            ->where('job.review', 'rejected')
            ->where('job.review_note', 'Speed hack in the replay')
            ->where('job.review_reasons', []));
    }

    public function test_reviews_refuse_a_stale_state(): void
    {
        [, , $job] = $this->flaggedJob();
        Moderation::approveJob($job, 'Fine');

        $this->expectExceptionMessage('This job is already approved.');
        Moderation::approveJob($job->refresh(), 'Fine');
    }

    public function test_the_admin_lists_pending_jobs_with_a_badge(): void
    {
        [, , $pending] = $this->flaggedJob();
        $clean = Delivery::factory()->create();
        $rejected = Delivery::factory()->create();
        Moderation::rejectJob($rejected, 'Cheated');

        $this->assertSame('1', DeliveryResource::getNavigationBadge());

        $this->actingAs($this->staff());
        Livewire::test(ListDeliveries::class, ['activeTab' => 'pending'])
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$clean, $rejected]);
        Livewire::test(ListDeliveries::class, ['activeTab' => 'rejected'])
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$clean, $pending]);

        Moderation::approveJob($pending, 'Fine');
        $this->assertNull(DeliveryResource::getNavigationBadge());
    }

    public function test_the_migration_turns_staff_flags_into_rejections(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $staffFlagged = Delivery::factory()->create();
        $checkFlagged = Delivery::factory()->create();
        DB::table('deliveries')->where('id', $staffFlagged->id)->update(['hub_flag_reason' => Moderation::STAFF_FLAG_PREFIX.'Teleported', 'staff_reviewed' => true]);
        DB::table('deliveries')->where('id', $checkFlagged->id)->update(['hub_flag_reason' => 'Under 1 km']);

        $this->artisan('migrate')->assertSuccessful();

        $staffFlagged->refresh();
        $this->assertSame(Delivery::REVIEW_REJECTED, $staffFlagged->review);
        $this->assertSame('Teleported', $staffFlagged->review_note);
        $this->assertNull($staffFlagged->hub_flag_reason);
        $this->assertSame(Delivery::REVIEW_PENDING, $checkFlagged->refresh()->reviewState());
    }
}
