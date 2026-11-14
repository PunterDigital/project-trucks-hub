<?php

namespace Tests\Feature;

use App\Models\LiveStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The live map (#4687): positions from the app, who appears, and the opt-out. */
class LiveMapTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private static function payload(array $position = ['x' => -12345.7, 'z' => 4321.0, 'heading_deg' => 90.0]): array
    {
        return [
            'truck' => ['make' => 'Volvo', 'model' => 'FH'],
            'job' => [
                'cargo_name' => 'Cement',
                'source_city' => 'Berlin',
                'destination_city' => 'Praha',
            ],
            'position' => $position,
        ];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('ProjectTrucks', ['sync'])->plainTextToken;
    }

    /** @param  array<string, mixed>  $attributes */
    private function liveDriver(array $attributes = [], array $status = []): User
    {
        $user = User::factory()->create($attributes);
        LiveStatus::query()->create([
            'user_id' => $user->id,
            'truck_make' => 'Scania',
            'truck_model' => 'S',
            'pos_x' => 1000.5,
            'pos_z' => -2000.25,
            'heading_deg' => 45,
            'updated_at' => now(),
            ...$status,
        ]);

        return $user;
    }

    public function test_the_api_stores_the_position(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('api.status.update'), self::payload())
            ->assertOk();

        $live = LiveStatus::query()->findOrFail($user->id);
        $this->assertSame(-12345.7, $live->pos_x);
        $this->assertSame(4321.0, $live->pos_z);
        $this->assertSame(90.0, $live->heading_deg);
    }

    public function test_a_status_without_a_position_still_works_and_clears_the_old_one(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withToken($token)->putJson(route('api.status.update'), self::payload())->assertOk();
        $old = self::payload();
        unset($old['position']);
        $this->withToken($token)->putJson(route('api.status.update'), $old)->assertOk();

        $this->assertNull(LiveStatus::query()->findOrFail($user->id)->pos_x);
    }

    public function test_the_api_refuses_nonsense_positions(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->putJson(route('api.status.update'), self::payload(['x' => 'far', 'z' => 5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('position.x');

        $this->withToken($this->tokenFor($user))
            ->putJson(route('api.status.update'), self::payload(['x' => 1, 'z' => 5_000_000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('position.z');
    }

    public function test_drivers_who_opted_out_never_have_a_position_stored(): void
    {
        $user = User::factory()->create(['show_on_map' => false]);

        $this->withToken($this->tokenFor($user))
            ->putJson(route('api.status.update'), self::payload())
            ->assertOk();

        $live = LiveStatus::query()->findOrFail($user->id);
        $this->assertNull($live->pos_x);
        $this->assertNull($live->pos_z);
        $this->assertSame('Volvo', $live->truck_make);
    }

    public function test_switching_the_map_off_drops_the_stored_position_at_once(): void
    {
        $user = $this->liveDriver();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'show_on_map' => '0',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertFalse($user->fresh()?->show_on_map);
        $this->assertNull(LiveStatus::query()->findOrFail($user->id)->pos_x);
    }

    public function test_the_map_shows_fresh_public_opted_in_drivers_only(): void
    {
        $shown = $this->liveDriver(['name' => 'Shown Driver']);
        $this->liveDriver(['name' => 'Private Driver', 'profile_private' => true]);
        $this->liveDriver(['name' => 'Opted Out', 'show_on_map' => false]);
        $this->liveDriver(['name' => 'Gone Home'], ['updated_at' => now()->subSeconds(LiveStatus::FRESH_SECONDS + 5)]);
        $this->liveDriver(['name' => 'No Position'], ['pos_x' => null, 'pos_z' => null]);

        $this->get(route('map'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('map/Index')
                ->has('drivers', 1)
                ->where('drivers.0.id', $shown->id)
                ->where('drivers.0.x', 1000.5)
                ->where('drivers.0.z', -2000.3)
                ->where('drivers.0.truck', 'Scania S')
                ->where('drivers.0.job', null)
                ->where('drivers.0.driver.name', 'Shown Driver')
                ->missing('drivers.0.driver.email')
                ->where('refreshSeconds', 30));
    }

    public function test_a_driver_on_a_job_shows_the_job(): void
    {
        $this->liveDriver([], ['cargo_name' => 'Apples', 'source_city' => 'Calais', 'dest_city' => 'Paris']);

        $this->get(route('map'))->assertInertia(fn (Assert $page) => $page
            ->where('drivers.0.job', ['cargo' => 'Apples', 'from' => 'Calais', 'to' => 'Paris']));
    }

    public function test_tile_info_comes_from_the_generated_file(): void
    {
        $dir = public_path('map-tiles/ets2');
        $file = $dir.'/TileMapInfo.json';
        $existing = is_file($file) ? File::get($file) : null;

        try {
            File::ensureDirectoryExists($dir);
            File::put($file, (string) json_encode(['x1' => -100, 'x2' => 300, 'y1' => -50, 'y2' => 350, 'minZoom' => 0, 'maxZoom' => 6]));

            $this->get(route('map'))->assertInertia(fn (Assert $page) => $page
                ->where('tiles.x1', -100)
                ->where('tiles.y2', 350)
                ->where('tiles.maxZoom', 6)
                ->where('tiles.url', fn (string $url) => str_starts_with($url, '/map-tiles/ets2/tiles/{z}/{x}/{y}.png')));
        } finally {
            $existing === null ? File::delete($file) : File::put($file, $existing);
        }
    }
}
