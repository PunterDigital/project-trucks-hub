<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\DeliveryStatus;
use App\Enums\Recruitment;
use App\Http\Controllers\Settings\PrivacyController;
use App\Models\Company;
use App\Models\CompanyAward;
use App\Models\Delivery;
use App\Models\User;
use App\Support\CompanyAwards;
use App\Support\CompanyMemberStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/** Driver of the month awards and the member stats CSV (#5226). */
class CompanyAwardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'));
    }

    private function company(string $name = 'Award Haulage', string $tag = 'AWD'): Company
    {
        return Company::factory()->create(['name' => $name, 'tag' => $tag, 'recruitment' => Recruitment::Open]);
    }

    private function member(Company $company, CompanyRole $role, array $state = []): User
    {
        $user = User::factory()->create($state);
        $company->members()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function job(User $user, ?Company $company, float $km, string $completedAt, array $state = []): Delivery
    {
        $at = CarbonImmutable::parse($completedAt, 'UTC');

        return Delivery::factory()->for($user)->create([
            'company_id' => $company?->id,
            'distance_km' => $km,
            'status' => DeliveryStatus::Delivered,
            'started_at' => $at->subHours(3),
            'completed_at' => $at,
            ...$state,
        ]);
    }

    /** @return list<array{int, int, float}> [place, user id, km] */
    private function awards(Company $company, string $month): array
    {
        return CompanyAward::query()->where('company_id', $company->id)->where('month', $month)->orderBy('place')->get()
            ->map(fn (CompanyAward $a) => [$a->place, $a->user_id, $a->distance_km])->all();
    }

    public function test_the_top_three_by_counted_distance_win_and_ties_go_to_the_earlier_first_delivery(): void
    {
        $company = $this->company();
        $other = $this->company('Other Freight', 'OTH');
        [$ann, $ben, $cat, $dan] = [
            $this->member($company, CompanyRole::Owner),
            $this->member($company, CompanyRole::Driver),
            $this->member($company, CompanyRole::Driver),
            $this->member($company, CompanyRole::Driver),
        ];

        $this->job($ann, $company, 900, '2026-10-20 10:00');
        // Ben and Cat tie on 500 km: Cat's first delivery of the month came earlier.
        $this->job($ben, $company, 300, '2026-10-12 10:00');
        $this->job($ben, $company, 200, '2026-10-13 10:00');
        $this->job($cat, $company, 500, '2026-10-05 10:00');
        $this->job($dan, $company, 100, '2026-10-06 10:00');
        // Not counted for October: another month, cancelled, hidden, flagged, another company, no company.
        $this->job($dan, $company, 5000, '2026-09-30 23:59');
        $this->job($dan, $company, 5000, '2026-11-01 00:00');
        $this->job($dan, $company, 5000, '2026-10-07 10:00', ['status' => DeliveryStatus::Cancelled]);
        $this->job($dan, $company, 5000, '2026-10-07 11:00', ['hidden' => true]);
        $this->job($dan, $company, 5000, '2026-10-07 12:00', ['flagged' => true]);
        $this->job($dan, $other, 5000, '2026-10-08 10:00');
        $this->job($dan, null, 5000, '2026-10-09 10:00');

        $this->assertSame(2, CompanyAwards::award('2026-10'));

        $this->assertSame([[1, $ann->id, 900.0], [2, $cat->id, 500.0], [3, $ben->id, 500.0]], $this->awards($company, '2026-10'));
        $this->assertSame([[1, $dan->id, 5000.0]], $this->awards($other, '2026-10'));
    }

    public function test_a_rerun_changes_nothing_even_after_a_late_sync(): void
    {
        $company = $this->company();
        $ann = $this->member($company, CompanyRole::Owner);
        $ben = $this->member($company, CompanyRole::Driver);
        $this->job($ann, $company, 300, '2026-10-10 10:00');

        $this->artisan('hub:monthly-awards')->assertSuccessful()->expectsOutputToContain('Awarded 2026-10: 1 companies.');
        $this->job($ben, $company, 900, '2026-10-11 10:00');
        $this->artisan('hub:monthly-awards', ['--month' => '2026-10'])->assertSuccessful()->expectsOutputToContain('0 companies');

        $this->assertSame([[1, $ann->id, 300.0]], $this->awards($company, '2026-10'));
    }

    public function test_an_unfinished_month_is_refused(): void
    {
        $this->artisan('hub:monthly-awards', ['--month' => '2026-11'])->assertFailed();
        $this->artisan('hub:monthly-awards', ['--month' => 'soon'])->assertFailed();

        $this->expectException(InvalidArgumentException::class);
        CompanyAwards::award('2026-12');
    }

    public function test_the_company_page_and_the_profile_show_awards_with_private_drivers_hidden_from_outsiders(): void
    {
        $company = $this->company();
        $ann = $this->member($company, CompanyRole::Owner, ['name' => 'Ann Award']);
        $pia = $this->member($company, CompanyRole::Driver, ['name' => 'Pia Private', 'profile_private' => true]);
        $this->job($ann, $company, 900, '2026-10-20 10:00');
        $this->job($pia, $company, 400, '2026-10-21 10:00');
        CompanyAwards::award('2026-10');

        $this->get(route('companies.show', $company))->assertInertia(fn (Assert $page) => $page
            ->where('awards.0.month', '2026-10')
            ->where('awards.0.label', 'October 2026')
            ->where('awards.0.winners.0.driver.name', 'Ann Award')
            ->where('awards.0.winners.1.place', 2)
            ->where('awards.0.winners.1.driver.name', 'Private driver')
            ->where('awards.0.winners.1.driver.username', null)
            ->where('memberStatsPeriods', []));

        $this->actingAs($pia)->get(route('companies.show', $company))->assertInertia(fn (Assert $page) => $page
            ->where('awards.0.winners.1.driver.name', 'Pia Private')
            ->where('memberStatsPeriods', []));

        $this->actingAs($ann)->get(route('companies.show', $company))->assertInertia(fn (Assert $page) => $page
            ->where('memberStatsPeriods.0', ['value' => '2026-11', 'label' => 'November 2026'])
            ->where('memberStatsPeriods.12', ['value' => 'all', 'label' => 'All time']));

        $this->get(route('drivers.show', $ann))->assertInertia(fn (Assert $page) => $page
            ->where('awards', [[
                'month' => '2026-10',
                'label' => 'October 2026',
                'place' => 1,
                'company' => ['name' => 'Award Haulage', 'slug' => $company->slug, 'tag' => 'AWD'],
            ]]));
    }

    public function test_awards_older_than_twelve_months_drop_off_the_pages(): void
    {
        $company = $this->company();
        $ann = $this->member($company, CompanyRole::Owner);
        $this->job($ann, $company, 100, '2025-10-10 10:00');
        $this->job($ann, $company, 100, '2025-11-10 10:00');
        CompanyAwards::award('2025-10');
        CompanyAwards::award('2025-11');

        $this->assertSame(['2025-11'], array_column(CompanyAwards::forCompany($company, null, null), 'month'));
        $this->assertSame(['2025-11'], array_column(CompanyAwards::forDriver($ann), 'month'));
    }

    public function test_managers_download_member_stats_and_drivers_cannot(): void
    {
        $company = $this->company();
        $ann = $this->member($company, CompanyRole::Owner, ['name' => 'Ann Award', 'username' => 'ann-award']);
        $mo = $this->member($company, CompanyRole::Manager, ['name' => 'Mo Manager', 'username' => 'mo-manager']);
        $eve = $this->member($company, CompanyRole::Driver, ['name' => '=HYPERLINK("x")', 'username' => 'eve-evil']);
        $outsider = User::factory()->create();
        $this->job($ann, $company, 300, '2026-10-10 10:00', ['income' => 1000, 'game_revenue' => 1200]);
        $this->job($ann, $company, 200, '2026-10-12 10:00', ['income' => 800, 'status' => DeliveryStatus::Late]);
        $this->job($ann, $company, 100, '2026-11-02 08:30', ['income' => 500]);
        $this->job($ann, null, 999, '2026-10-15 10:00');

        $response = $this->actingAs($mo)->get(route('companies.member-stats', [$company, 'period' => '2026-10']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$company->slug.'-member-stats-2026-10.csv"');
        $content = (string) $response->getContent();
        $this->assertStringEndsWith("\r\n", $content);
        $this->assertSame([
            CompanyMemberStats::COLUMNS,
            // A name that a spreadsheet would run as a formula is sent as text.
            ["'=HYPERLINK(\"x\")", 'eve-evil', 'driver', '0', '0', '0', '', ''],
            // Income is what the game paid when it said so (#5227); one of two on time.
            ['Ann Award', 'ann-award', 'owner', '2', '500', '2000', '50', '2026-10-12 10:00'],
            ['Mo Manager', 'mo-manager', 'manager', '0', '0', '0', '', ''],
        ], $this->parse($content));

        $all = $this->parse((string) $this->actingAs($ann)->get(route('companies.member-stats', $company))->assertOk()->getContent());
        $this->assertSame(['Ann Award', 'ann-award', 'owner', '3', '600', '2500', '66.7', '2026-11-02 08:30'], $all[2]);

        $this->actingAs($eve)->get(route('companies.member-stats', $company))->assertForbidden();
        $this->actingAs($outsider)->get(route('companies.member-stats', $company))->assertForbidden();
        $this->actingAs($ann)->get(route('companies.member-stats', [$company, 'period' => '2024-01']))->assertNotFound();
        $this->actingAs($ann)->get(route('companies.member-stats', [$company, 'period' => ['x']]))->assertNotFound();
    }

    /** @return list<list<string|null>> */
    private function parse(string $csv): array
    {
        return array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), explode("\r\n", rtrim($csv, "\r\n")));
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $company = $this->company();

        $this->get(route('companies.member-stats', $company))->assertRedirect(route('login'));
    }

    public function test_the_privacy_export_lists_the_drivers_awards(): void
    {
        $company = $this->company();
        $ann = $this->member($company, CompanyRole::Owner);
        $this->job($ann, $company, 300, '2026-10-10 10:00');
        CompanyAwards::award('2026-10');

        $this->assertSame(
            [['month' => '2026-10', 'place' => 1, 'distance_km' => 300.0, 'company' => 'Award Haulage']],
            PrivacyController::exportFor($ann)['company_awards'],
        );
    }
}
