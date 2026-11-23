<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordApiUsage;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SignOutSuspended;
use App\Http\Middleware\ThrottleAccountRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ability' => CheckForAnyAbility::class,
        ]);

        // API usage counters (#4667) must wrap auth and throttling to count 401s and 429s.
        // Laravel sorts every middleware in its priority list ahead of the route group's
        // others, so it has to be in that list itself.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: RecordApiUsage::class);

        // Security headers (#5533) on every response: pages, API, media, feeds and errors.
        $middleware->prepend(SecurityHeaders::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            // Card 5532: per-IP limits on sign-up and "forgot password".
            ThrottleAccountRequests::class,
            SignOutSuspended::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Sentry (#4653): reports only when SENTRY_LARAVEL_DSN is set (production).
        Integration::handles($exceptions);
    })->create();
