<?php

namespace Tests\Unit;

use App\Auth\SteamProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\User;
use ReflectionMethod;
use Tests\TestCase;

/** The profile lookup after Steam's OpenID has already told us the SteamID. */
class SteamProviderTest extends TestCase
{
    private const STEAM_ID = '76561198000000001';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private function provider(?string $key, array $responses = []): SteamProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $provider = new SteamProvider(Request::create('/'), null, $key, 'http://localhost/auth/steam/callback');
        $provider->setHttpClient(new Client(['handler' => $stack]));

        return $provider;
    }

    private function lookup(SteamProvider $provider): User
    {
        $get = new ReflectionMethod($provider, 'getUserByToken');
        $map = new ReflectionMethod($provider, 'mapUserToObject');

        return $map->invoke($provider, $get->invoke($provider, self::STEAM_ID));
    }

    public function test_without_a_key_it_returns_the_steam_id_and_calls_nothing(): void
    {
        $user = $this->lookup($this->provider(null));

        $this->assertSame(self::STEAM_ID, $user->getId());
        $this->assertNull($user->getNickname());
        $this->assertNull($user->getAvatar());
        $this->assertSame([], $this->history);
    }

    public function test_with_a_key_it_reads_the_profile_over_https(): void
    {
        $user = $this->lookup($this->provider('k3y', [new Response(200, [], json_encode(['response' => ['players' => [[
            'steamid' => self::STEAM_ID,
            'personaname' => 'Big Rig Dave',
            'avatarmedium' => 'https://avatars.example.com/m.jpg',
            'avatarfull' => 'https://avatars.example.com/full.jpg',
        ]]]]))]));

        $this->assertSame('Big Rig Dave', $user->getNickname());
        $this->assertSame('https://avatars.example.com/full.jpg', $user->getAvatar());

        /** @var Psr7Request $request */
        $request = $this->history[0]['request'];
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api.steampowered.com', $request->getUri()->getHost());
        $this->assertStringContainsString('steamids='.self::STEAM_ID, $request->getUri()->getQuery());
    }

    public function test_a_failed_or_empty_lookup_still_signs_in(): void
    {
        $failed = $this->lookup($this->provider('k3y', [new ConnectException('down', new Psr7Request('GET', 'https://api.steampowered.com'))]));
        $this->assertSame(self::STEAM_ID, $failed->getId());

        $empty = $this->lookup($this->provider('k3y', [new Response(200, [], '{"response":{"players":[]}}')]));
        $this->assertSame(self::STEAM_ID, $empty->getId());
        $this->assertNull($empty->getNickname());
    }

    public function test_a_profile_for_another_steam_id_is_ignored(): void
    {
        $user = $this->lookup($this->provider('k3y', [new Response(200, [], json_encode(['response' => ['players' => [[
            'steamid' => '76561198999999999',
            'personaname' => 'Someone Else',
        ]]]]))]));

        $this->assertSame(self::STEAM_ID, $user->getId());
        $this->assertNull($user->getNickname());
    }
}
