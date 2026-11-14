<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/** Settings → Linked apps: the ProjectTrucks installs that can sync to this account. */
class DevicesController extends Controller
{
    public function index(Request $request): Response
    {
        $tokens = $request->user()->tokens()->latest()->get()->map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'created_at' => $t->created_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
        ]);

        return Inertia::render('settings/Devices', [
            'devices' => $tokens,
            'jobsSynced' => $request->user()->deliveries()->count(),
        ]);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('App unlinked. It can no longer sync.')]);

        return to_route('devices.index');
    }
}
