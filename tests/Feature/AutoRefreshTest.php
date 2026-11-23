<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\HomeController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use App\Notifications\HubNotice;
use App\Support\AutoRefresh;
use App\Support\CompanyAwards;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The minute auto-refresh (#5731): Home, a company page and a driver profile send the props
 * they keep live, the refresh asks for just those (a partial Inertia reload), and the server
 * runs only their queries. Query counts are printed with PAO_DISABLE=1 for the PR.
 */
class AutoRefreshTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));

        // A company with history: members, a year of jobs, goals and awards in past months.
        $this->company = Company::factory()->create(['name' => 'Refresh Haulage', 'tag' => 'RFH']);
        $this->owner = User::factory()->create(['name' => 'Ola Owner']);
        $this->company->members()->create(['user_id' => $this->owner->id, 'role' => CompanyRole::Owner]);
        foreach (range(1, 3) as $i) {
            $driver = User::factory()->create();
            $this->company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);
            foreach (range(0, 11) as $month) {
                $this->job($driver, CarbonImmutable::now()->subMonthsNoOverflow($month)->subDays($i));
            }
        }
        foreach (range(0, 11) as $month) {
            $this->job($this->owner, CarbonImmutable::now()->subMonthsNoOverflow($month)->subHours(5));
            $this->company->goals()->create([
                'month' => CarbonImmutable::now()->subMonthsNoOverflow($month)->format('Y-m'),
                'metric' => 'distance_km',
                'target' => 1000,
            ]);
        }
        foreach (range(1, 6) as $month) {
            CompanyAwards::award(CarbonImmutable::now()->subMonthsNoOverflow($month)->format('Y-m'));
        }

        $this->viewer = User::factory()->create(['name' => 'Vic Viewer']);
        $this->viewer->notify(HubNotice::appLinked('ProjectTrucks'));
    }

    private function job(User $user, CarbonImmutable $at): void
    {
        Delivery::factory()->for($user)->create([
            'company_id' => $this->company->id,
            'distance_km' => 400,
            'duration_sec' => 6 * 3600,
            'avg_speed_kmh' => 66,
            'started_at' => $at->subHours(6),
            'completed_at' => $at,
        ]);
    }

    /**
     * An Inertia reload as the browser sends it: everything (`$only` null, what the refresh
     * did before #5731) or a partial one for `$only`.
     *
     * @param  list<string>|null  $only
     * @return array{TestResponse<Response>, int}
     */
    private function reload(string $url, string $component, ?array $only): array
    {
        $version = app(HandleInertiaRequests::class)->version(request());
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version];
        if ($only !== null) {
            $headers['X-Inertia-Partial-Component'] = $component;
            $headers['X-Inertia-Partial-Data'] = implode(',', $only);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->viewer)->get($url, $headers);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertJsonPath('component', $component);

        return [$response, $queries];
    }

    /** @return list<array{string, string, list<string>}> url, component, the page's live props */
    private function pages(): array
    {
        return [
            ['/', 'Home', HomeController::LIVE_PROPS],
            ['/companies/'.$this->company->slug, 'companies/Show', CompanyController::LIVE_PROPS],
            ['/drivers/'.$this->owner->username, 'drivers/Show', DriverController::LIVE_PROPS],
        ];
    }

    public function test_pages_send_their_live_props_with_the_bell_and_the_user(): void
    {
        foreach ($this->pages() as [$url, $component, $live]) {
            $this->actingAs($this->viewer)->get($url)->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->where('refreshOnly', AutoRefresh::only($live)));
        }

        $this->assertSame(['onTheRoad', 'latest', 'news', 'auth', 'notifications'], AutoRefresh::only(HomeController::LIVE_PROPS));
    }

    public function test_a_refresh_answers_only_the_live_props_with_far_fewer_queries(): void
    {
        foreach ($this->pages() as [$url, $component, $live]) {
            [, $full] = $this->reload($url, $component, null);
            [$partial, $queries] = $this->reload($url, $component, AutoRefresh::only($live));

            $props = array_keys((array) $partial->json('props'));
            sort($props);
            $expected = [...AutoRefresh::only($live), 'errors'];
            sort($expected);
            $this->assertSame($expected, array_values(array_intersect($props, $expected)), $component);
            // Nothing heavy comes back (shared non-closure props such as `name` may).
            $this->assertEmpty(array_intersect($props, ['totals', 'topDrivers', 'goals', 'awards', 'chart', 'members', 'charts', 'trucks', 'progress']), $component);

            fwrite(STDERR, sprintf("\n  %s: full reload %d queries, refresh %d", $component, $full, $queries));
            $this->assertLessThan($full, $queries, $component);
            $this->assertLessThanOrEqual(12, $queries, $component);
        }
    }

    public function test_the_bell_count_still_comes_with_a_refresh(): void
    {
        [$response] = $this->reload('/companies/'.$this->company->slug, 'companies/Show', AutoRefresh::only(CompanyController::LIVE_PROPS));
        $response->assertJsonPath('props.notifications.unread', 1);
        $response->assertJsonPath('props.auth.user.name', 'Vic Viewer');

        $this->viewer->notify(HubNotice::appLinked('ProjectTrucks'));
        [$response] = $this->reload('/companies/'.$this->company->slug, 'companies/Show', AutoRefresh::only(CompanyController::LIVE_PROPS));
        $response->assertJsonPath('props.notifications.unread', 2);
    }
}
