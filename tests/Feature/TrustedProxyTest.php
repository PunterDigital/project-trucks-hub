<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Behind Cloudflare (#5703): only Cloudflare's ranges are trusted, so `$request->ip()` is the
 * visitor's address for per-IP limits, and a forged X-Forwarded-For from anyone else changes
 * nothing.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]));
    }

    public function test_a_cloudflare_edge_passes_the_visitor_address_on(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '162.158.10.20'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.5', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_test/ip')
            ->assertJson(['ip' => '203.0.113.5', 'secure' => true]);

        // IPv6 edge, and an address the visitor put in front of Cloudflare's is skipped.
        $this->withServerVariables(['REMOTE_ADDR' => '2606:4700::1'])
            ->withHeaders(['X-Forwarded-For' => '10.0.0.1, 203.0.113.5'])
            ->getJson('/_test/ip')
            ->assertJson(['ip' => '203.0.113.5']);
    }

    public function test_anyone_else_keeps_their_own_address(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.5', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_test/ip')
            ->assertJson(['ip' => '198.51.100.7', 'secure' => false]);
    }

    public function test_a_forwarded_host_is_never_trusted(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '162.158.10.20'])
            ->withHeaders(['X-Forwarded-Host' => 'evil.example'])
            ->getJson('/_test/ip')
            ->assertJson(['host' => parse_url((string) config('app.url'), PHP_URL_HOST)]);
    }
}
