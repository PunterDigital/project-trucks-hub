<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (#5533), the same set as the other Laravel apps.
 *
 * - HSTS for a year, no `preload` yet. Browsers ignore it over plain http, so it goes out on
 *   every response: Cloudflare sits in front of Forge, so `isSecure()` can't be trusted here.
 * - `frame-ancestors 'none'`: nothing frames the Hub (Forge's nginx adds X-Frame-Options
 *   SAMEORIGIN; CSP wins where both are set, so we don't send a second X-Frame-Options).
 *   No full CSP: Filament/Livewire, Leaflet and the inline styles would need a nonce setup;
 *   `base-uri` and `object-src` are free.
 * - nosniff, Referrer-Policy and a Permissions-Policy that turns off what the Hub never uses
 *   (passkeys stay allowed: `publickey-credentials-*` isn't listed).
 */
class SecurityHeaders
{
    public const HEADERS = [
        'Strict-Transport-Security' => 'max-age=31536000',
        'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
