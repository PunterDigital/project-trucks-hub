<?php

namespace Tests\Feature\Admin;

use App\Admin\DailyStats;
use App\Enums\DeliveryStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ActiveDriversChart;
use App\Filament\Widgets\LinkRequestsChart;
use App\Filament\Widgets\PlatformStatsOverview;
use App\Filament\Widgets\RecentlyFlaggedJobs;
use App\Filament\Widgets\RecentSignups;
use App\Filament\Widgets\SignupsChart;
use App\Filament\Widgets\TopCompaniesThisWeek;
use App\Filament\Widgets\TopDriversThisWeek;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\Delivery;
use App\Models\DeviceAuthorization;
use App\Models\LiveStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    /** Wednesday 18 November 2026, 12:00 UTC: "this week" starts Monday the 16th. */
    private const NOW = '2026-11-18 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create(['created_at' => '2026-10-01 09:00:00']);
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    private function link(string $created, ?string $approved = null, ?string $denied = null, ?string $expires = null): DeviceAuthorization
    {
        return DeviceAuthorization::query()->forceCreate([
            'device_code_hash' => hash('sha256', Str::random(20)),
            'user_code' => strtoupper(Str::random(8)),
            'client_name' => 'ProjectTrucks',
            'approved_at' => $approved,
            'denied_at' => $denied,
            'expires_at' => $expires ?? CarbonImmutable::parse($created)->addMinutes(15),
            'created_at' => $created,
            'updated_at' => $created,
        ]);
    }

    public function test_a_day_counts_only_what_happened_inside_it(): void
    {
        $day = CarbonImmutable::parse('2026-11-17', 'UTC');

        $email = User::factory()->create(['created_at' => '2026-11-17 00:00:00']);
        User::factory()->create(['created_at' => '2026-11-17 23:59:59', 'steam_id' => '7656119']);
        User::factory()->create(['created_at' => '2026-11-16 23:59:59']);
        User::factory()->create(['created_at' => '2026-11-18 00:00:00']);

        // Two jobs by one driver and one by another inside the day; one the day before.
        Delivery::factory()->for($email)->create(['created_at' => '2026-11-17 08:00:00', 'distance_km' => 100.25]);
        Delivery::factory()->for($email)->create(['created_at' => '2026-11-17 09:00:00', 'distance_km' => 50]);
        Delivery::factory()->create(['created_at' => '2026-11-17 10:00:00', 'distance_km' => 10, 'flagged' => true]);
        Delivery::factory()->create(['created_at' => '2026-11-16 22:00:00', 'distance_km' => 999]);

        $company = Company::factory()->create(['created_at' => '2026-11-17 12:00:00']);
        Company::factory()->create(['created_at' => '2026-11-18 01:00:00']);

        $applicant = User::factory()->create(['created_at' => '2026-11-01 00:00:00']);
        CompanyApplication::query()->forceCreate(['company_id' => $company->id, 'user_id' => $applicant->id, 'status' => 'pending', 'created_at' => '2026-11-17 13:00:00']);
        CompanyApplication::query()->forceCreate(['company_id' => $company->id, 'user_id' => $email->id, 'status' => 'accepted', 'created_at' => '2026-11-16 13:00:00', 'decided_at' => '2026-11-17 14:00:00']);

        $this->link('2026-11-17 10:00:00', approved: '2026-11-17 10:05:00');
        $this->link('2026-11-17 11:00:00');                                     // expired 11:15
        $this->link('2026-11-17 12:00:00', denied: '2026-11-17 12:01:00');      // denied, not expired
        $this->link('2026-11-17 23:50:00', expires: '2026-11-18 00:05:00');     // expires the next day

        $this->assertEquals([
            'signups_email' => 1,
            'signups_steam' => 1,
            'jobs_synced' => 3,
            'km_synced' => 160.25,
            'active_drivers' => 2,
            'companies_created' => 1,
            'applications_sent' => 1,
            'applications_accepted' => 1,
            'links_started' => 4,
            'links_approved' => 1,
            'links_expired' => 1,
        ], DailyStats::forDay($day));
    }

    public function test_today_only_counts_link_requests_that_have_already_expired(): void
    {
        $this->link('2026-11-18 11:00:00');                                     // expired 11:15
        $this->link('2026-11-18 11:55:00');                                     // still open until 12:10

        $today = DailyStats::forDay(CarbonImmutable::parse('2026-11-18', 'UTC'));

        $this->assertSame(2.0, $today['links_started']);
        $this->assertSame(1.0, $today['links_expired']);
    }

    public function test_fill_stores_closed_days_once_and_never_today(): void
    {
        User::factory()->create(['created_at' => '2026-11-15 10:00:00']);

        $this->assertSame(3, DailyStats::fill());   // 15th, 16th, 17th
        $this->assertSame(0, DailyStats::fill());

        $this->assertSame(
            ['2026-11-15', '2026-11-16', '2026-11-17'],
            DB::table('daily_stats')->distinct()->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all(),
        );
        $this->assertSame(count(DailyStats::METRICS) * 3, DB::table('daily_stats')->count());
        $this->assertEquals(1, DB::table('daily_stats')->where(['date' => '2026-11-15', 'metric' => 'signups_email'])->value('value'));

        // Stored days are final until forced.
        User::factory()->create(['created_at' => '2026-11-15 11:00:00']);
        DailyStats::fill();
        $this->assertEquals(1, DB::table('daily_stats')->where(['date' => '2026-11-15', 'metric' => 'signups_email'])->value('value'));

        $this->assertSame(3, DailyStats::fill(force: true));
        $this->assertEquals(2, DB::table('daily_stats')->where(['date' => '2026-11-15', 'metric' => 'signups_email'])->value('value'));
    }

    public function test_days_before_the_first_account_are_zeros(): void
    {
        User::factory()->create(['created_at' => '2026-11-17 10:00:00']);

        $this->assertSame(5, DailyStats::fill(CarbonImmutable::parse('2026-11-13', 'UTC')));
        $this->assertEquals(0, DB::table('daily_stats')->where('date', '<', '2026-11-17')->sum('value'));
    }

    public function test_series_has_one_value_per_day_with_today_live(): void
    {
        User::factory()->create(['created_at' => '2026-11-16 10:00:00']);
        User::factory()->create(['created_at' => '2026-11-18 09:00:00']);
        User::factory()->create(['created_at' => '2026-11-18 10:00:00', 'steam_id' => '1']);

        $series = DailyStats::series(7);

        $this->assertSame(['2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18'], $series['dates']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 1.0], $series['values']['signups_email']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0], $series['values']['signups_steam']);
        // Closed days were stored on the way; today was not.
        $this->assertTrue(DB::table('daily_stats')->where('date', '2026-11-17')->exists());
        $this->assertFalse(DB::table('daily_stats')->where('date', '2026-11-18')->exists());
    }

    public function test_periods_compare_with_the_window_before_and_count_active_drivers_once(): void
    {
        $driver = User::factory()->create(['created_at' => '2026-11-01 10:00:00']);
        $other = User::factory()->create(['created_at' => '2026-11-01 10:00:00']);

        // Current 7 days = 12th–18th; previous = 5th–11th.
        Delivery::factory()->for($driver)->create(['created_at' => '2026-11-13 10:00:00']);
        Delivery::factory()->for($driver)->create(['created_at' => '2026-11-14 10:00:00']);
        Delivery::factory()->for($driver)->create(['created_at' => '2026-11-18 10:00:00']);
        Delivery::factory()->for($other)->create(['created_at' => '2026-11-06 10:00:00']);

        $periods = DailyStats::periods(7);

        $this->assertSame(3.0, $periods['current']['jobs_synced']);
        $this->assertSame(1.0, $periods['previous']['jobs_synced']);
        // The same driver on three days is one active driver, not three.
        $this->assertSame(1.0, $periods['current']['active_drivers']);
        $this->assertSame(1.0, $periods['previous']['active_drivers']);
        $this->assertSame([0.0, 1.0, 1.0, 0.0, 0.0, 0.0, 1.0], $periods['daily']['jobs_synced']);
    }

    public function test_the_change_line(): void
    {
        $this->assertSame('No change', PlatformStatsOverview::change(0, 0)[0]);
        $this->assertSame('Up from 0', PlatformStatsOverview::change(4, 0)[0]);
        $this->assertSame(['Up 50%', 'success'], array_slice(PlatformStatsOverview::change(3, 2), 0, 2));
        $this->assertSame(['Down 25%', 'danger'], array_slice(PlatformStatsOverview::change(3, 4), 0, 2));
    }

    public function test_the_command_fills_and_checks_its_dates(): void
    {
        User::factory()->create(['created_at' => '2026-11-16 10:00:00']);

        $this->artisan('hub:daily-stats')->expectsOutput('Stored 2 days.')->assertSuccessful();
        $this->artisan('hub:daily-stats')->expectsOutput('Stored 0 days.')->assertSuccessful();
        $this->artisan('hub:daily-stats --from=2026-11-17 --force')->expectsOutput('Stored 1 day.')->assertSuccessful();
        $this->artisan('hub:daily-stats --from=yesterday')->assertExitCode(2);
    }

    public function test_link_counts_are_stored_before_old_requests_are_pruned(): void
    {
        User::factory()->create(['created_at' => '2026-11-01 10:00:00']);
        $old = $this->link('2026-11-16 20:00:00', expires: '2026-11-16 20:15:00');

        $this->postJson('/api/v1/device/code', ['client_name' => 'ProjectTrucks'])->assertOk();

        $this->assertModelMissing($old);
        $this->assertEquals(1, DB::table('daily_stats')->where(['date' => '2026-11-16', 'metric' => 'links_started'])->value('value'));
        $this->assertEquals(1, DB::table('daily_stats')->where(['date' => '2026-11-16', 'metric' => 'links_expired'])->value('value'));
    }

    public function test_the_period_falls_back_to_seven_days(): void
    {
        $this->assertSame(30, Dashboard::days(['days' => '30']));
        $this->assertSame(7, Dashboard::days(['days' => '365']));
        $this->assertSame(7, Dashboard::days(null));
    }

    public function test_staff_see_the_stat_cards_and_charts(): void
    {
        $this->actingAs($this->staff());
        $driver = User::factory()->create(['created_at' => '2026-11-17 10:00:00']);
        Delivery::factory()->for($driver)->create(['created_at' => '2026-11-18 10:00:00', 'distance_km' => 1234]);
        LiveStatus::query()->create(['user_id' => $driver->id, 'updated_at' => now()]);

        Livewire::test(PlatformStatsOverview::class, ['pageFilters' => ['days' => 30]])
            ->assertSee('Email sign-ups')
            ->assertSee('Steam sign-ups')
            ->assertSee('Drivers with a linked app')
            ->assertSee('1,234 km')
            ->assertSee('On the road now')
            ->assertSee('Last 30 days, compared with the 30 days before');

        Livewire::test(SignupsChart::class, ['pageFilters' => ['days' => 7]])->assertSee('Sign-ups per day');
        Livewire::test(ActiveDriversChart::class)->assertSee('Active drivers per day');
        Livewire::test(LinkRequestsChart::class)->assertSee('ProjectTrucks link requests per day');
    }

    public function test_staff_see_the_tables(): void
    {
        $this->actingAs($this->staff());

        $fast = User::factory()->create(['name' => 'Fast Freddie', 'created_at' => '2026-11-10 10:00:00']);
        $slow = User::factory()->create(['name' => 'Slow Sally', 'created_at' => '2026-11-10 10:00:00']);
        $company = Company::factory()->create(['name' => 'Speedy Haulage']);
        Delivery::factory()->for($fast)->create(['company_id' => $company->id, 'distance_km' => 500, 'completed_at' => '2026-11-17 10:00:00']);
        Delivery::factory()->for($slow)->create(['distance_km' => 100, 'completed_at' => '2026-11-16 10:00:00']);
        // Last week and flagged jobs don't count towards "this week".
        $lastWeek = Delivery::factory()->create(['distance_km' => 900, 'completed_at' => '2026-11-15 23:00:00']);
        $flagged = Delivery::factory()->create(['distance_km' => 900, 'completed_at' => '2026-11-17 10:00:00', 'hub_flag_reason' => 'Average speed over 150 km/h']);
        $appFlagged = Delivery::factory()->create(['completed_at' => '2026-11-17 10:00:00', 'flagged' => true, 'flag_reason' => 'Teleported']);
        $cancelled = Delivery::factory()->create(['completed_at' => '2026-11-17 10:00:00', 'status' => DeliveryStatus::Cancelled]);

        Livewire::test(TopDriversThisWeek::class)
            ->assertCanSeeTableRecords([$fast, $slow], inOrder: true)
            ->assertCanNotSeeTableRecords([$lastWeek->user, $flagged->user, $cancelled->user]);

        Livewire::test(TopCompaniesThisWeek::class)
            ->assertCanSeeTableRecords([$company])
            ->assertSee('Speedy Haulage');

        Livewire::test(RecentSignups::class)->assertSee('Fast Freddie');

        Livewire::test(RecentlyFlaggedJobs::class)
            ->assertCanSeeTableRecords([$flagged, $appFlagged])
            ->assertCanNotSeeTableRecords([$lastWeek, $cancelled])
            ->assertSee('Average speed over 150 km/h')
            ->assertSee('Teleported');
    }

    public function test_the_dashboard_has_the_period_picker(): void
    {
        $this->actingAs($this->staff())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Last 7 days')
            ->assertSee('Last 90 days');
    }
}
