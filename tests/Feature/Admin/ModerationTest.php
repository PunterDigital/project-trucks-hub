<?php

namespace Tests\Feature\Admin;

use App\Admin\Moderation;
use App\Enums\CompanyRole;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Deliveries\Pages\ViewDelivery;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\AdminAuditLog;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\LiveStatus;
use App\Models\Report;
use App\Models\User;
use App\Support\Leaderboard;
use App\Support\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Api\JobSyncTest;
use Tests\TestCase;

/** Moderation (#4651): reports from drivers, and the staff actions in the admin panel. */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    private function report(User $reporter, string $type, int $id, string $reason = 'offensive'): TestResponse
    {
        return $this->actingAs($reporter)->post(route('reports.store'), [
            'type' => $type,
            'id' => $id,
            'reason' => $reason,
            'details' => 'Rude name',
        ]);
    }

    // --- Reports ---------------------------------------------------------------------------

    public function test_a_driver_reports_a_driver_a_company_and_a_job(): void
    {
        $reporter = User::factory()->create();
        $driver = User::factory()->create(['username' => 'rude-rick']);
        $company = Company::factory()->create();
        $job = Delivery::factory()->for($driver)->create();

        $this->report($reporter, 'driver', $driver->id)->assertRedirect();
        $this->report($reporter, 'company', $company->id)->assertRedirect();
        $this->report($reporter, 'job', $job->id, 'cheating')->assertRedirect();

        $this->assertSame(3, Report::query()->open()->where('reporter_id', $reporter->id)->count());
        $report = Report::query()->where('reportable_type', Delivery::class)->firstOrFail();
        $this->assertTrue($report->reportable?->is($job));
        $this->assertSame('Rude name', $report->details);
    }

    public function test_one_open_report_per_item_per_driver(): void
    {
        $reporter = User::factory()->create();
        $driver = User::factory()->create();

        $this->report($reporter, 'driver', $driver->id);
        $this->report($reporter, 'driver', $driver->id, 'spam')->assertRedirect();
        $this->assertSame(1, Report::query()->count());

        // Once staff resolve it, a new report is fine.
        Moderation::resolveReport(Report::query()->firstOrFail(), 'Talked to them', $this->staff());
        $this->report($reporter, 'driver', $driver->id);
        $this->assertSame(2, Report::query()->count());

        // Someone else can report the same driver.
        $this->report(User::factory()->create(), 'driver', $driver->id);
        $this->assertSame(2, Report::query()->open()->count());
    }

    public function test_drivers_cant_report_themselves_their_jobs_or_their_company(): void
    {
        $driver = User::factory()->create();
        $company = Company::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);
        $job = Delivery::factory()->for($driver)->create();

        $this->report($driver, 'driver', $driver->id);
        $this->report($driver, 'job', $job->id);
        $this->report($driver, 'company', $company->id);

        $this->assertSame(0, Report::query()->count());
    }

    public function test_reports_need_an_account_a_valid_reason_and_a_visible_item(): void
    {
        $driver = User::factory()->create();
        $this->post(route('reports.store'), ['type' => 'driver', 'id' => $driver->id, 'reason' => 'spam'])
            ->assertRedirect(route('login'));

        $reporter = User::factory()->create();
        $this->report($reporter, 'driver', $driver->id, 'boring')->assertSessionHasErrors('reason');
        $this->report($reporter, 'admin', $driver->id)->assertSessionHasErrors('type');

        $private = User::factory()->create(['profile_private' => true]);
        $this->report($reporter, 'driver', $private->id)->assertNotFound();
        $this->report($reporter, 'job', 999999)->assertNotFound();

        $this->assertSame(0, Report::query()->count());
    }

    public function test_staff_see_the_queue_and_resolve_a_report(): void
    {
        $staff = $this->staff();
        $driver = User::factory()->create(['name' => 'Rude Rick']);
        $this->report(User::factory()->create(), 'driver', $driver->id);
        $report = Report::query()->firstOrFail();

        $this->actingAs($staff);
        $this->assertSame('1', ReportResource::getNavigationBadge());
        $this->get(ReportResource::getUrl('index'))->assertOk();
        $this->get(ReportResource::getUrl('view', ['record' => $report]))->assertOk()->assertSee('Rude Rick');

        Livewire::test(ListReports::class)->assertCanSeeTableRecords([$report]);

        Livewire::test(ViewReport::class, ['record' => $report->getKey()])
            ->callAction('resolve', data: ['reason' => 'Asked them to change it'])
            ->assertNotified('Report resolved');

        $report->refresh();
        $this->assertSame(Report::RESOLVED, $report->status);
        $this->assertSame($staff->id, $report->resolved_by);
        $this->assertSame('Asked them to change it', $report->resolution_note);
        $this->assertNull(ReportResource::getNavigationBadge());
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'report.resolved', 'staff_id' => $staff->id]);

        Livewire::test(ListReports::class)->assertCanNotSeeTableRecords([$report]);
        Livewire::test(ListReports::class, ['activeTab' => 'resolved'])->assertCanSeeTableRecords([$report]);
    }

    public function test_a_report_resolves_only_once(): void
    {
        $this->report(User::factory()->create(), 'driver', User::factory()->create()->id);
        $report = Report::query()->firstOrFail();
        Moderation::resolveReport($report, 'Done', $this->staff());

        $this->expectException(RuntimeException::class);
        Moderation::resolveReport($report->refresh(), 'Again', $this->staff());
    }

    public function test_a_report_whose_item_was_deleted_still_opens(): void
    {
        $driver = User::factory()->create();
        $this->report(User::factory()->create(), 'driver', $driver->id);
        $driver->delete();

        $this->actingAs($this->staff())
            ->get(ReportResource::getUrl('view', ['record' => Report::query()->firstOrFail()]))
            ->assertOk()
            ->assertSee('deleted since');
    }

    // --- Suspension -----------------------------------------------------------------------

    public function test_suspending_signs_out_revokes_apps_clears_live_status_and_hides_the_driver(): void
    {
        $staff = $this->staff();
        $driver = User::factory()->create(['username' => 'sus-sam']);
        $driver->createToken('ProjectTrucks', ['sync']);
        LiveStatus::query()->create(['user_id' => $driver->id, 'source_city' => 'Berlin', 'pos_x' => 1, 'pos_z' => 2]);
        $job = Delivery::factory()->for($driver)->create();

        $this->actingAs($staff);
        Livewire::test(ViewUser::class, ['record' => $driver->getKey()])
            ->callAction('suspend', data: ['reason' => 'Hate speech in name'])
            ->assertNotified('Account suspended');

        $driver->refresh();
        $this->assertTrue($driver->isSuspended());
        $this->assertSame('Hate speech in name', $driver->suspension_reason);
        $this->assertSame(0, $driver->tokens()->count());
        $this->assertNull(LiveStatus::query()->find($driver->id));
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'user.suspended', 'target_id' => $driver->id]);

        // Off public pages and stats.
        auth()->logout();
        $this->get(route('drivers.show', 'sus-sam'))->assertNotFound();
        $this->get(route('jobs.show', $job))->assertNotFound();
        $this->assertSame(0, Delivery::query()->counted()->count());
        $this->assertSame([], Leaderboard::drivers(Period::All, 'distance'));

        // Signed in already: the next request signs them out.
        $this->actingAs($driver)->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();
    }

    public function test_lifting_a_suspension_brings_the_driver_back(): void
    {
        $staff = $this->staff();
        $driver = User::factory()->create(['username' => 'back-bob']);
        Delivery::factory()->for($driver)->create();
        Moderation::suspend($driver, 'Spam', $staff);

        $this->actingAs($staff);
        Livewire::test(ViewUser::class, ['record' => $driver->getKey()])
            ->assertActionHidden('suspend')
            ->callAction('liftSuspension', data: ['reason' => 'Appeal accepted'])
            ->assertNotified('Suspension lifted');

        $this->assertFalse($driver->refresh()->isSuspended());
        $this->assertSame(1, Delivery::query()->counted()->count());
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'user.suspension_lifted']);

        auth()->logout();
        $this->get(route('drivers.show', 'back-bob'))->assertOk();
    }

    public function test_staff_cant_suspend_themselves_or_twice(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff);
        Livewire::test(ViewUser::class, ['record' => $staff->getKey()])
            ->callAction('suspend', data: ['reason' => 'Oops'])
            ->assertNotified('You can’t suspend your own account.');
        $this->assertFalse($staff->refresh()->isSuspended());

        $driver = User::factory()->create();
        Moderation::suspend($driver, 'Spam', $staff);
        $this->expectException(RuntimeException::class);
        Moderation::suspend($driver->refresh(), 'Again', $staff);
    }

    public function test_a_suspended_staff_member_loses_the_panel(): void
    {
        $staff = $this->staff();
        $staff->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($staff)->get('/admin')->assertNotFound();
    }

    public function test_a_link_approved_before_the_suspension_gets_no_token(): void
    {
        $driver = User::factory()->create();
        $code = $this->postJson(route('api.device.code'), ['client_name' => 'ProjectTrucks'])->assertOk()->json();
        $this->actingAs($driver)->post(route('link.update'), ['user_code' => $code['user_code'], 'decision' => 'approve']);
        $this->assertDatabaseHas('device_authorizations', ['user_id' => $driver->id]);
        Moderation::suspend($driver, 'Cheating', $this->staff());
        auth()->logout();

        $this->postJson(route('api.device.token'), ['device_code' => $code['device_code']])
            ->assertJson(['error' => 'access_denied']);
        $this->assertSame(0, $driver->tokens()->count());
    }

    // --- Jobs -----------------------------------------------------------------------------

    public function test_staff_hide_a_job_the_driver_cant_show_again(): void
    {
        $staff = $this->staff();
        $driver = User::factory()->create();
        $job = Delivery::factory()->for($driver)->create();

        $this->actingAs($staff);
        Livewire::test(ViewDelivery::class, ['record' => $job->getKey()])
            ->callAction('hideJob', data: ['reason' => 'Rude cargo name'])
            ->assertNotified('Job hidden');

        $this->assertTrue($job->refresh()->staff_hidden);
        $this->assertSame(0, Delivery::query()->counted()->count());
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'job.hidden', 'target_id' => $job->id]);

        auth()->logout();
        $this->get(route('jobs.show', $job))->assertNotFound();

        // The driver still sees it, with the note, and "show to others" doesn't undo staff.
        $this->actingAs($driver)->get(route('jobs.show', $job))->assertOk()
            ->assertInertia(fn ($page) => $page->where('job.staff_hidden', true));
        $this->patch(route('jobs.update', $job), ['hidden' => false]);
        $this->assertTrue($job->refresh()->staff_hidden);

        $this->actingAs($staff);
        Livewire::test(ViewDelivery::class, ['record' => $job->getKey()])
            ->callAction('unhideJob', data: ['reason' => 'Mistake'])
            ->assertNotified('Job shown again');
        $this->assertFalse($job->refresh()->staff_hidden);
        $this->assertSame(1, Delivery::query()->counted()->count());
    }

    public function test_staff_reject_a_job_and_approve_it_again(): void
    {
        $staff = $this->staff();
        $job = Delivery::factory()->create();

        $this->actingAs($staff);
        Livewire::test(ViewDelivery::class, ['record' => $job->getKey()])
            ->assertActionHidden('approveJob')
            ->callAction('rejectJob', data: ['reason' => 'Teleported'])
            ->assertNotified('Job rejected');

        $job->refresh();
        $this->assertSame(Delivery::REVIEW_REJECTED, $job->review);
        $this->assertSame('Teleported', $job->review_note);
        $this->assertTrue($job->staff_reviewed);
        $this->assertSame(0, Delivery::query()->counted()->count());
        // Staff aren't the driver: a rejected job is a 404 to them on the site too.
        $this->get(route('jobs.show', $job))->assertNotFound();
        $this->actingAs($job->user)->get(route('jobs.show', $job))->assertInertia(fn ($page) => $page
            ->where('job.review', 'rejected')
            ->where('job.review_note', 'Teleported'));

        $this->actingAs($staff);
        Livewire::test(ViewDelivery::class, ['record' => $job->getKey()])
            ->assertActionHidden('rejectJob')
            ->callAction('approveJob', data: ['reason' => 'Checked the replay'])
            ->assertNotified('Job approved');

        $this->assertSame(Delivery::REVIEW_APPROVED, $job->refresh()->review);
        $this->assertSame(1, Delivery::query()->counted()->count());
        $this->assertSame(2, AdminAuditLog::query()->where('target_id', $job->id)->where('target_type', 'Delivery')->count());
    }

    public function test_a_job_nothing_flagged_cant_be_approved(): void
    {
        $job = Delivery::factory()->create();

        $this->expectExceptionMessage('This job isn’t waiting for a review.');
        Moderation::approveJob($job, 'Fine', $this->staff());
    }

    public function test_an_approved_check_flag_stays_approved_on_re_sync(): void
    {
        $driver = User::factory()->create();
        $token = $driver->createToken('ProjectTrucks', ['sync'])->plainTextToken;
        // An implausible job: 150 km in 10 in-game minutes.
        $implausible = JobSyncTest::job([
            'distance_km' => 150, 'duration_sec' => 600, 'game_duration_min' => 10, 'avg_speed_kmh' => 900,
            'started_at' => '2026-11-14T10:00:00Z', 'completed_at' => '2026-11-14T10:10:00Z',
        ]);
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [$implausible]])->assertOk();
        $job = Delivery::query()->where('user_id', $driver->id)->firstOrFail();
        $this->assertNotNull($job->hub_flag_reason, 'the checks should flag this job');

        $this->assertSame(0, Delivery::query()->counted()->count());

        Moderation::approveJob($job, 'Checked, fine', $this->staff());

        // The app sends it again: staff's decision stands.
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [$implausible]])
            ->assertOk()
            ->assertJsonPath('jobs.0.review', 'approved');
        $this->assertSame(Delivery::REVIEW_APPROVED, $job->refresh()->review);
        $this->assertSame(1, Delivery::query()->counted()->count());
    }

    // --- Companies ------------------------------------------------------------------------

    public function test_staff_reset_a_company_profile(): void
    {
        $staff = $this->staff();
        $company = Company::factory()->create(['name' => 'Rude Name Ltd', 'tag' => 'RUDE', 'description' => 'Rude text']);

        $this->actingAs($staff);
        Livewire::test(ViewCompany::class, ['record' => $company->getKey()])
            ->callAction('resetProfile', data: ['reason' => 'Offensive name'])
            ->assertNotified('Company profile reset');

        $company->refresh();
        $this->assertSame('Company '.$company->id, $company->name);
        $this->assertSame('C'.$company->id, $company->tag);
        $this->assertSame('company-'.$company->id, $company->slug);
        $this->assertNull($company->description);

        $log = AdminAuditLog::query()->where('action', 'company.profile_reset')->firstOrFail();
        $this->assertSame('Rude Name Ltd', $log->before['name'] ?? null);
        $this->get(route('companies.show', $company->slug))->assertOk();
    }

    public function test_staff_remove_a_member_but_not_the_owner(): void
    {
        $staff = $this->staff();
        $company = Company::factory()->create();
        $owner = User::factory()->create();
        $driver = User::factory()->create(['name' => 'Dirk Driver']);
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
        $member = $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);

        $this->actingAs($staff);
        Livewire::test(ViewCompany::class, ['record' => $company->getKey()])
            ->callAction('removeMember', data: ['member' => $member->id, 'reason' => 'Harassing others'])
            ->assertNotified('Member removed');

        $this->assertNull($driver->refresh()->membership);
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'company.member_removed', 'target_id' => $company->id]);

        $ownerMember = $company->members()->where('user_id', $owner->id)->firstOrFail();
        $this->expectException(RuntimeException::class);
        Moderation::removeMember($ownerMember, 'No', $staff);
    }
}
