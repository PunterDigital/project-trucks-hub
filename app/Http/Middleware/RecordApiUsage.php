<?php

namespace App\Http\Middleware;

use App\Admin\ApiUsage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts every ProjectTrucks API request into the hour's row per endpoint (#4667): requests,
 * bytes received, 429s and other errors, and for job syncs how many jobs were stored, skipped,
 * Hub-flagged or refused. Outermost on the API group, so throttled and unauthenticated
 * answers are counted too. No per-request rows, nothing about who sent it.
 */
class RecordApiUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        ApiUsage::record($request, $response);

        return $response;
    }
}
