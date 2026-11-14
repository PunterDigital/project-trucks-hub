<?php

namespace Tests\Feature\Api;

use App\Models\DeviceAuthorization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DeviceLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('device-token');
    }

    private function start(): array
    {
        return $this->postJson(route('api.device.code'), ['client_name' => 'ProjectTrucks on RIG'])
            ->assertOk()
            ->json();
    }

    public function test_the_full_link_flow_issues_a_sync_token(): void
    {
        $start = $this->start();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $start['user_code']);
        $this->assertSame(route('link.show'), $start['verification_uri']);
        $this->assertSame(5, $start['interval']);

        $this->postJson(route('api.device.token'), ['device_code' => $start['device_code']])
            ->assertStatus(400)
            ->assertJson(['error' => 'authorization_pending']);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('link.show', ['code' => strtolower($start['user_code'])]))
            ->assertInertia(fn ($page) => $page
                ->component('link/Show')
                ->where('request.client_name', 'ProjectTrucks on RIG')
                ->where('invalid', false));

        $this->actingAs($user)
            ->post(route('link.update'), ['user_code' => $start['user_code'], 'decision' => 'approve'])
            ->assertRedirect(route('devices.index'));

        $this->travel(6)->seconds();
        $token = $this->postJson(route('api.device.token'), ['device_code' => $start['device_code']])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.username', $user->username)
            ->json('access_token');

        $this->assertSame(['sync'], $user->tokens()->first()->abilities);
        $this->assertSame('ProjectTrucks on RIG', $user->tokens()->first()->name);

        // The code is single use.
        $this->postJson(route('api.device.token'), ['device_code' => $start['device_code']])
            ->assertJson(['error' => 'expired_token']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('api.me'))
            ->assertOk()
            ->assertJsonPath('username', $user->username)
            ->assertJsonMissingPath('email');
    }

    public function test_polling_faster_than_the_interval_gets_slow_down(): void
    {
        $start = $this->start();
        $this->postJson(route('api.device.token'), ['device_code' => $start['device_code']])
            ->assertJson(['error' => 'authorization_pending']);
        $this->postJson(route('api.device.token'), ['device_code' => $start['device_code']])
            ->assertJson(['error' => 'slow_down']);
    }

    public function test_a_declined_request_is_refused(): void
    {
        $start = $this->start();
        $this->actingAs(User::factory()->create())
            ->post(route('link.update'), ['user_code' => $start['user_code'], 'decision' => 'deny']);

        $this->postJson(route('api.device.token'), ['device_code' => $start['device_code']])
            ->assertStatus(400)
            ->assertJson(['error' => 'access_denied']);
        $this->assertSame(0, DeviceAuthorization::query()->count());
    }

    public function test_codes_expire_after_fifteen_minutes(): void
    {
        $start = $this->start();
        $this->travel(16)->minutes();

        $this->actingAs(User::factory()->create())
            ->post(route('link.update'), ['user_code' => $start['user_code'], 'decision' => 'approve'])
            ->assertSessionHasErrors('user_code');

        $this->postJson(route('api.device.token'), ['device_code' => $start['device_code']])
            ->assertJson(['error' => 'expired_token']);
    }

    public function test_an_unknown_code_shows_as_invalid(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('link.show', ['code' => 'ZZZZ-ZZZZ']))
            ->assertInertia(fn ($page) => $page->where('invalid', true)->where('request', null));
    }

    public function test_approving_needs_a_signed_in_driver(): void
    {
        $start = $this->start();
        $this->get(route('link.show', ['code' => $start['user_code']]))->assertRedirect(route('login'));
        $this->post(route('link.update'), ['user_code' => $start['user_code'], 'decision' => 'approve'])
            ->assertRedirect(route('login'));
    }

    public function test_linked_apps_can_be_listed_and_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('ProjectTrucks on RIG', ['sync']);

        $this->actingAs($user)->get(route('devices.index'))
            ->assertInertia(fn ($page) => $page->component('settings/Devices')->has('devices', 1));

        $this->actingAs($user)->delete(route('devices.destroy', $token->accessToken->id))
            ->assertRedirect(route('devices.index'));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_drivers_cannot_revoke_someone_elses_app(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('PT', ['sync']);

        $this->actingAs(User::factory()->create())->delete(route('devices.destroy', $token->accessToken->id));
        $this->assertSame(1, $owner->tokens()->count());
    }
}
