<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Tests\TestCase;

/** Sentry error tracking (#4653). */
class SentryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_off_in_tests_and_never_sends_personal_data(): void
    {
        $this->assertNull(config('sentry.dsn'), 'phpunit.xml forces an empty DSN');
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertNull(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn());
    }

    public function test_an_empty_env_line_means_off_not_an_empty_string(): void
    {
        // `.env.example` (copied to `.env` in CI) has `SENTRY_LARAVEL_DSN=`: '' must become null.
        putenv('SENTRY_LARAVEL_DSN=');
        putenv('SENTRY_ENVIRONMENT=');
        putenv('SENTRY_TRACES_SAMPLE_RATE=');
        try {
            $config = require config_path('sentry.php');
        } finally {
            putenv('SENTRY_LARAVEL_DSN');
            putenv('SENTRY_ENVIRONMENT');
            putenv('SENTRY_TRACES_SAMPLE_RATE');
        }

        $this->assertNull($config['dsn']);
        $this->assertNull($config['environment']);
        $this->assertNull($config['traces_sample_rate']);
        $this->assertSame(1.0, $config['sample_rate']);
    }

    public function test_only_the_user_id_is_attached_to_events(): void
    {
        $user = User::factory()->create(['name' => 'Private Name', 'email' => 'secret@example.com']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $sentUser = null;
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use (&$sentUser): void {
            $sentUser = $scope->getUser();
        });

        $this->assertNotNull($sentUser);
        $this->assertSame($user->id, $sentUser->getId());
        $this->assertNull($sentUser->getEmail());
        $this->assertNull($sentUser->getUsername());
        $this->assertNull($sentUser->getIpAddress());
    }
}
