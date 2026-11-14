<?php

namespace App\Auth;

use Illuminate\Support\Arr;
use Laravel\Socialite\Two\User;
use SocialiteProviders\Steam\Provider;
use Throwable;

/**
 * Sign in through Steam.
 *
 * Who the player is comes from Steam's OpenID provider alone: the parent class sends them to
 * steamcommunity.com/openid/login and verifies Steam's signed answer with Steam directly
 * (`validate()`), which yields the 64-bit SteamID. No Web API key is involved in that.
 *
 * The Web API key (`STEAM_API_KEY`) is optional and only fetches the display name and avatar
 * (ISteamUser/GetPlayerSummaries). Unlike the parent, this works without a key, calls the API
 * over https (the parent used plain http, sending the key in clear), and treats a failed or
 * empty profile lookup as "no profile" instead of failing the sign-in.
 */
class SteamProvider extends Provider
{
    private const PLAYER_SUMMARIES_URL = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/';

    /**
     * @param  string|int|null  $token  the SteamID OpenID returned
     * @return array<string, mixed>|null
     */
    protected function getUserByToken($token)
    {
        if ($token === null || ! ctype_digit((string) $token) || (string) $token === '0') {
            return null;
        }

        $steamId = (string) $token;
        $fallback = ['steamid' => $steamId];

        if (empty($this->clientSecret)) {
            return $fallback;
        }

        try {
            $response = $this->getHttpClient()->get(self::PLAYER_SUMMARIES_URL, [
                'query' => ['key' => $this->clientSecret, 'steamids' => $steamId],
                'timeout' => 5,
            ]);
            $player = Arr::get((array) json_decode((string) $response->getBody(), true), 'response.players.0');
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }

        return is_array($player) && (string) ($player['steamid'] ?? '') === $steamId ? $player : $fallback;
    }

    /**
     * @param  array<string, mixed>|null  $user
     */
    protected function mapUserToObject($user)
    {
        if ($user === null) {
            throw new \RuntimeException('Steam did not return a SteamID.');
        }

        return (new User)->setRaw($user)->map([
            'id' => (string) $user['steamid'],
            'nickname' => Arr::get($user, 'personaname'),
            'name' => Arr::get($user, 'realname'),
            'email' => null,
            'avatar' => Arr::get($user, 'avatarfull') ?? Arr::get($user, 'avatarmedium'),
        ]);
    }
}
