<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SteamController;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use App\Support\Feed;
use App\Support\Leaderboard;
use App\Support\Period;
use App\Support\Stats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();

        return Inertia::render('Home', [
            'canRegister' => Features::enabled(Features::registration()),
            'steamEnabled' => SteamController::enabled(),
            'totals' => [
                ...Stats::totals(Delivery::query()),
                'drivers' => User::query()->count(),
                'companies' => Company::query()->count(),
            ],
            'onTheRoad' => Feed::onTheRoad($viewer, 8),
            'latest' => Feed::latestDeliveries($viewer, 12),
            'topDrivers' => Leaderboard::drivers(Period::Week, 'distance', 5),
            'downloadUrl' => route('download'),
        ]);
    }
}
