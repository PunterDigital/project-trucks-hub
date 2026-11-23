<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Card 5532: per-IP limits on sign-up and "forgot password", and "forgot
 * password" doesn't say whether an address has an account.
 */
class AccountHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_ups_are_limited_per_ip(): void
    {
        Notification::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('register.store'), $this->signUp($i))->assertSessionHasNoErrors();
            auth()->logout();
        }

        $this->post(route('register.store'), $this->signUp(6))
            ->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in 60 minutes.']);

        $this->assertSame(5, User::query()->where('email', 'like', 'person%')->count());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(route('register.store'), $this->signUp(7))
            ->assertSessionHasNoErrors();
    }

    public function test_password_reset_requests_are_limited_per_ip(): void
    {
        Notification::fake();

        for ($i = 1; $i <= 6; $i++) {
            $this->post(route('password.email'), ['email' => "nobody{$i}@example.com"])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => 'nobody7@example.com'])
            ->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in 1 minute.']);
    }

    public function test_forgot_password_answers_the_same_for_an_unknown_address(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $responses = [
            $this->post(route('password.email'), ['email' => $user->email]),
            $this->post(route('password.email'), ['email' => 'nobody@example.com']),
            // The broker throttles a repeat for the same account: same answer.
            $this->post(route('password.email'), ['email' => $user->email]),
        ];

        foreach ($responses as $response) {
            $response->assertSessionHasNoErrors()
                ->assertSessionHas('status', __(Password::RESET_LINK_SENT));
        }
    }

    /**
     * @return array<string, string>
     */
    private function signUp(int $n): array
    {
        return [
            'name' => 'Test User',
            'email' => "person{$n}@example.com",
            'username' => "person-{$n}",
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
    }
}
