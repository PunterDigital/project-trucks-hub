<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Auth\SteamController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Connections: the linked Steam account. */
class ConnectionsController extends Controller
{
    public function edit(Request $request): Response
    {
        $steamId = $request->user()->steam_id;

        return Inertia::render('settings/Connections', [
            'steamEnabled' => SteamController::enabled(),
            'steam' => $steamId ? [
                'id' => $steamId,
                'profile_url' => 'https://steamcommunity.com/profiles/'.$steamId,
            ] : null,
        ]);
    }
}
