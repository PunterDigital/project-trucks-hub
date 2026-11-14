<?php

namespace App\Http\Controllers;

use App\Support\Leaderboard;
use App\Support\Period;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaderboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $board = $request->query('board') === 'companies' ? 'companies' : 'drivers';
        $period = Period::tryFrom((string) $request->query('period')) ?? Period::Week;
        $metric = in_array($request->query('metric'), Leaderboard::METRICS, true)
            ? (string) $request->query('metric')
            : 'distance';

        return Inertia::render('leaderboards/Index', [
            'board' => $board,
            'period' => $period->value,
            'metric' => $metric,
            'rows' => $board === 'companies'
                ? Leaderboard::companies($period, $metric)
                : Leaderboard::drivers($period, $metric),
        ]);
    }
}
