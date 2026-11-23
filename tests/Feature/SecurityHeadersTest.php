<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Security headers from the app (#5533): every kind of response gets the same set. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertSecured(TestResponse $response): void
    {
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        // Forge's nginx already sends X-Frame-Options; a second one from the app would clash.
        $response->assertHeaderMissing('X-Frame-Options');
    }

    public function test_pages_api_answers_errors_and_feeds_all_carry_the_headers(): void
    {
        $this->assertSecured($this->get(route('home'))->assertOk());
        $this->assertSecured($this->get('/sitemap.xml')->assertOk());
        $this->assertSecured($this->get('/blog/feed')->assertOk());
        $this->assertSecured($this->get('/up')->assertOk());
        $this->assertSecured($this->get('/no-such-page')->assertNotFound());
        $this->assertSecured($this->getJson('/api/v1/me')->assertUnauthorized());
        $this->assertSecured($this->get(route('login'))->assertOk());

        $this->assertSecured($this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk());
    }

    public function test_hsts_has_no_preload_yet(): void
    {
        $this->assertStringNotContainsString('preload', SecurityHeaders::HEADERS['Strict-Transport-Security']);
    }
}
