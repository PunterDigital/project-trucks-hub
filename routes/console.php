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
