<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel is Filament, not Inertia. An Inertia visit that lands here (a redirect after
 * login, a `<Link>`) would show the Filament page inside an error modal, so tell Inertia to load
 * it as a full page.
 */
class LeaveInertia
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasHeader('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        return $next($request);
    }
}
