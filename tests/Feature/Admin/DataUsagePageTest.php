<?php

namespace Tests\Feature\Admin;

use App\Admin\ApiUsage;
use App\Filament\Widgets\ApiEndpointsTable;
use App\Filament\Widgets\ApiTrafficChart;
use App\Filament\Widgets\DataUsageOverview;
use App\Filament\Widgets\DriverDataTable;
use App\Models\Delivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Api\JobSyncTest;
use Tests\TestCase;

class DataUsagePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 14:20:00', 'UTC'));
    }

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    private function driverToken(User $user): string
    {
        return $user->createToken('ProjectTrucks', ['sync'])->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function job(string $id, array $overrides = []): array
    {
        return JobSyncTest::job(['id' => $id, ...$overrides]);
    }

    private function hour(string $endpoint): object
    {
        return DB::table('api_usage_stats')->where(['endpoint' => $endpoint])->sole();
    }

    public function test_a_sync_counts_the_request_its_bytes_and_its_jobs(): void
    {
        $token = $this->driverToken(User::factory()->create());

        $this->withToken($token)->postJson('/api/v1/jobs', ['jobs' => [
            $this->job('a'), $this->job('b'), $this->job('c', ['source' => 'MANUAL']),
        ]])->assertOk();

        $row = $this->hour('jobs.store');
        $this->assertSame('2026-11-18 14:00:00', substr((string) $row->hour, 0, 19));
        $this->assertSame(1, (int) $row->requests);
        $this->assertGreaterThan(500, (int) $row->bytes_in);
        $this->assertSame(2, (int) $row->jobs_synced);
        $this->assertSame(1, (int) $row->jobs_skipped);
        $this->assertSame(0, (int) $row->jobs_rejected);
    }

    public function test_a_refused_batch_counts_every_job_in_it_as_rejected(): void
    {
        $token = $this->driverToken(User::factory()->create());

        $this->withToken($token)->postJson('/api/v1/jobs', ['jobs' => [
            $this->job('a', ['status' => 'NOPE']), $this->job('b'),
        ]])->assertUnprocessable();

        $row = $this->hour('jobs.store');
        $this->assertSame(1, (int) $row->errors);
        $this->assertSame(2, (int) $row->jobs_rejected);
        $this->assertSame(0, (int) $row->jobs_synced);
    }

    public function test_status_updates_unauthenticated_calls_and_429s_are_counted(): void
    {
        // Unauthenticated first: the test client keeps Sanctum's user once a token has worked.
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $token = $this->driverToken(User::factory()->create());
        $this->withToken($token)->putJson('/api/v1/status', ['truck' => ['make' => 'Scania']])->assertSuccessful();
        $this->withToken($token)->putJson('/api/v1/status', ['truck' => ['make' => 'Scania']])->assertSuccessful();

        $this->assertSame(2, (int) $this->hour('status.update')->requests);
        $this->assertSame(1, (int) $this->hour('me')->errors);

        for ($i = 0; $i < 121; $i++) {
            $last = $this->withToken($token)->getJson('/api/v1/me');
        }
        $last->assertStatus(429);
        // The sync limit (120 a minute) is per driver across endpoints: the two status
        // updates used two of it, so the last three of the 121 calls were refused.
        $me = $this->hour('me');
        $this->assertSame(122, (int) $me->requests);
        $this->assertSame(3, (int) $me->throttled);
        $this->assertSame(1, (int) $me->errors, 'a 429 is not also an error');
    }

    public function test_totals_daily_series_and_endpoints(): void
    {
        $now = now();
        DB::table('api_usage_stats')->insert([
            ['hour' => '2026-11-18 13:00:00', 'endpoint' => 'status.update', 'requests' => 60, 'bytes_in' => 6000, 'throttled' => 0, 'errors' => 0, 'jobs_synced' => 0, 'jobs_skipped' => 0, 'jobs_flagged' => 0, 'jobs_rejected' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['hour' => '2026-11-17 09:00:00', 'endpoint' => 'jobs.store', 'requests' => 3, 'bytes_in' => 9000, 'throttled' => 1, 'errors' => 1, 'jobs_synced' => 12, 'jobs_skipped' => 0, 'jobs_flagged' => 1, 'jobs_rejected' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['hour' => '2026-10-01 09:00:00', 'endpoint' => 'jobs.store', 'requests' => 99, 'bytes_in' => 1, 'throttled' => 0, 'errors' => 0, 'jobs_synced' => 0, 'jobs_skipped' => 0, 'jobs_flagged' => 0, 'jobs_rejected' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $day = ApiUsage::totals(24);
        $this->assertSame(60, $day['requests'], 'yesterday 09:00 is outside the last 24 hours');
        $this->assertSame(0, $day['jobs_synced']);
        $twoDays = ApiUsage::totals(48);
        $this->assertSame(63, $twoDays['requests']);
        $this->assertSame(12, $twoDays['jobs_synced']);
        $this->assertSame(2, $twoDays['jobs_rejected']);

        $daily = ApiUsage::daily(30);
        $this->assertCount(30, $daily);
        $this->assertSame(['requests' => 60, 'status_updates' => 60, 'throttled' => 0, 'bytes_in' => 6000], $daily['2026-11-18']);
        $this->assertSame(1, $daily['2026-11-17']['throttled']);

        $endpoints = ApiUsage::byEndpoint(7);
        $this->assertSame(['status.update', 'jobs.store'], array_column($endpoints, 'endpoint'));
        $this->assertSame(3, $endpoints[1]['requests']);
    }

    public function test_top_drivers_by_jobs_held(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        Delivery::factory()->count(3)->for($a)->create();
        Delivery::factory()->count(1)->for($b)->create();

        $top = ApiUsage::topDrivers(20);

        $this->assertSame([$a->id, $b->id], array_column($top, 'user_id'));
        $this->assertSame(3, $top[0]['jobs']);
    }

    public function test_staff_see_the_data_usage_page(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff)->get('/admin/data-usage')->assertOk()->assertSee('Data usage');
        $this->actingAs(User::factory()->create())->get('/admin/data-usage')->assertNotFound();

        $this->actingAs($staff);
        Livewire::test(DataUsageOverview::class)->assertSee('API requests')->assertSee('Rate limited (429)');
        Livewire::test(ApiTrafficChart::class)->assertSee('API requests per day, last 30 days (UTC)');
        Livewire::test(ApiEndpointsTable::class)->assertSee('No API traffic yet');
        Livewire::test(DriverDataTable::class)->assertSee('Data held per driver (top 20)');
    }
}
