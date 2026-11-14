<?php

namespace Tests\Feature\Api;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\LiveStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobSyncTest extends TestCase
{
    use RefreshDatabase;

    /** A row as ProjectTrucks stores it in its own `jobs` table. */
    public static function job(array $overrides = []): array
    {
        return [
            'id' => '7f1c6a8e-3c2b-4d9e-9a51-0c6f2b1e5d10',
            'truck_make' => 'Scania',
            'truck_model' => 'S',
            'source_city' => 'Calais',
            'source_company' => 'Tradeaux',
            'dest_city' => 'Paris',
            'dest_company' => 'Euroacres',
            'cargo_id' => 'apples',
            'cargo_name' => 'Apples',
            'cargo_mass_kg' => 18000,
            'declared_income' => 12450,
            'distance_km' => 291.4,
            'duration_sec' => 14400,
            'avg_speed_kmh' => 72.8,
            'fuel_used_l' => 95.2,
            'damage_delta' => 0.013,
            'started_at' => '2026-11-14T10:00:00Z',
            'completed_at' => '2026-11-14T14:00:00Z',
            'status' => 'DELIVERED',
            'source' => 'TELEMETRY',
            'flagged' => false,
            'flag_reason' => null,
            'notes' => 'Private note that must never leave the PC',
            ...$overrides,
        ];
    }

    private function driver(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('PT', ['sync'])->plainTextToken];
    }

    public function test_jobs_sync_and_resync_updates_the_same_row(): void
    {
        [$user, $token] = $this->driver();

        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [self::job()]])
            ->assertOk()
            ->assertJsonPath('synced', 1)
            ->assertJsonPath('jobs.0.id', self::job()['id']);

        $delivery = Delivery::query()->sole();
        $this->assertSame($user->id, $delivery->user_id);
        $this->assertSame(12450, $delivery->income);
        $this->assertSame('Paris', $delivery->dest_city);
        $this->assertSame('2026-11-14 14:00:00', $delivery->completed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('notes', $delivery->getAttributes());

        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [self::job(['declared_income' => 13000])]])
            ->assertOk();
        $this->assertSame(1, Delivery::query()->count());
        $this->assertSame(13000, Delivery::query()->sole()->income);
    }

    public function test_a_resync_keeps_the_hidden_flag_and_the_original_company(): void
    {
        [$user, $token] = $this->driver();
        $company = Company::factory()->create();
        $company->members()->create(['user_id' => $user->id, 'role' => CompanyRole::Driver]);

        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [self::job()]]);
        $delivery = Delivery::query()->sole();
        $this->assertSame($company->id, $delivery->company_id);
        $delivery->update(['hidden' => true]);

        $user->membership->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [self::job()]]);

        $delivery->refresh();
        $this->assertTrue($delivery->hidden);
        $this->assertSame($company->id, $delivery->company_id);
    }

    public function test_jobs_of_different_drivers_with_the_same_local_id_stay_apart(): void
    {
        [, $a] = $this->driver();
        [, $b] = $this->driver();

        $this->withToken($a)->postJson(route('api.jobs.store'), ['jobs' => [self::job()]])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($b)->postJson(route('api.jobs.store'), ['jobs' => [self::job()]])->assertOk();

        $this->assertSame(2, Delivery::query()->count());
    }

    public function test_invalid_jobs_are_refused_as_a_batch(): void
    {
        [, $token] = $this->driver();

        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [
            self::job(['id' => 'a']),
            self::job(['id' => 'b', 'status' => 'STOLEN']),
        ]])->assertUnprocessable()->assertJsonValidationErrors('jobs.1.status');

        $this->assertSame(0, Delivery::query()->count());
    }

    public function test_batches_are_capped(): void
    {
        [, $token] = $this->driver();
        $jobs = array_map(fn ($i) => self::job(['id' => 'job-'.$i]), range(1, 101));

        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => $jobs])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('jobs');
    }

    public function test_a_job_deleted_in_the_app_is_deleted_here(): void
    {
        [$user, $token] = $this->driver();
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [self::job()]]);
        $other = Delivery::factory()->create(['client_id' => self::job()['id']]);

        $this->withToken($token)->deleteJson(route('api.jobs.destroy', self::job()['id']))->assertNoContent();

        $this->assertSame(0, $user->deliveries()->count());
        $this->assertModelExists($other);
    }

    public function test_the_api_needs_a_sync_token(): void
    {
        $this->postJson(route('api.jobs.store'), ['jobs' => [self::job()]])->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('other', ['read'])->plainTextToken;
        $this->withToken($token)->postJson(route('api.jobs.store'), ['jobs' => [self::job()]])->assertForbidden();
    }

    public function test_unlinking_from_the_app_revokes_its_token(): void
    {
        [$user, $token] = $this->driver();
        $this->withToken($token)->deleteJson(route('api.token.destroy'))->assertNoContent();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_live_status_is_stored_and_goes_stale(): void
    {
        [$user, $token] = $this->driver();

        $this->withToken($token)->putJson(route('api.status.update'), [
            'truck' => ['make' => 'Volvo', 'model' => 'FH'],
            'job' => [
                'cargo_name' => 'Cement',
                'cargo_mass_kg' => 20000,
                'source_city' => 'Berlin',
                'destination_city' => 'Praha',
                'income' => 9000,
                'driven_km' => 120.5,
                'distance_left_km' => 230,
                'progress_pct' => 34.4,
                'parked' => false,
            ],
        ])->assertOk()->assertJsonPath('status.on_job', true)->assertJsonPath('status.dest_city', 'Praha');

        $status = LiveStatus::query()->sole();
        $this->assertTrue($status->isFresh());
        $this->assertSame(1, LiveStatus::query()->fresh()->count());

        $this->travel(LiveStatus::FRESH_SECONDS + 1)->seconds();
        $this->assertSame(0, LiveStatus::query()->fresh()->count());

        // Free roam: a truck and no job.
        $this->withToken($token)->putJson(route('api.status.update'), ['truck' => ['make' => 'Volvo', 'model' => 'FH'], 'job' => null])
            ->assertOk()->assertJsonPath('status.on_job', false);
        $this->assertSame(1, LiveStatus::query()->count());

        $this->withToken($token)->deleteJson(route('api.status.destroy'))->assertNoContent();
        $this->assertSame(0, LiveStatus::query()->count());
        $this->assertNotNull($user->fresh());
    }
}
