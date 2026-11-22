<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Last week's leaderboard is final on Monday: award "Top ten" (#4645). Needs the scheduler
// (`schedule:run` every minute) on the server; until then a driver gets it on their next sync.
Schedule::command('hub:progress --notify')->weeklyOn(1, '00:15')->withoutOverlapping();

// The admin dashboard's daily rollups for the day that just closed (#4665). Without the
// scheduler the dashboard fills missing days itself when it loads.
Schedule::command('hub:daily-stats')->dailyAt('00:05')->withoutOverlapping();

// The admin Database page's per-table sizes (#4666). The page also takes one when it opens.
Schedule::command('hub:db-snapshot')->dailyAt('00:10')->withoutOverlapping();

// The admin Storage page's daily disk and folder sizes (#4667). The page also takes one when it opens.
Schedule::command('hub:storage-snapshot')->dailyAt('00:20')->withoutOverlapping();

// New post files in resources/posts become blog drafts (#5153). The Blog posts list also runs it.
Schedule::command('hub:import-post-drafts')->hourly()->withoutOverlapping();

// Company activity requirement (#5225): tell owners and managers who missed last month's minimum.
Schedule::command('hub:activity-check')->monthlyOn(1, '00:30')->withoutOverlapping();

// Expired link requests, stale live statuses, old log files (#4667). Also buttons on the Storage page.
Schedule::command('hub:prune')->dailyAt('00:25')->withoutOverlapping();
