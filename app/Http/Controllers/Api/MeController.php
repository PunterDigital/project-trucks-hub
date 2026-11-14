<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MeController extends Controller
{
    /** Who this token belongs to; the app shows it in Settings → Account. */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('membership.company');

        return response()->json([
            ...$user->publicSummary(),
            'profile_url' => route('drivers.show', $user),
            'profile_private' => $user->profile_private,
            'jobs_synced' => $user->deliveries()->count(),
        ]);
    }

    /** "Unlink" in the app: revoke the token it is using. */
    public function destroyToken(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
