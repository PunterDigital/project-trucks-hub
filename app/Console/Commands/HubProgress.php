<?php

namespace App\Console\Commands;

use App\Achievements\Progress;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Works out every driver's level and achievements again (#4645). A migration runs it once
 * (silently) for the jobs synced before achievements existed; the scheduler runs it with
 * `--notify` every Monday, when last week's top 10 is final.
 */
class HubProgress extends Command
{
    protected $signature = 'hub:progress {--notify : Ring the bell for newly unlocked achievements}';

    protected $description = 'Recompute driver levels and achievements from their counted jobs';

    public function handle(): int
    {
        $unlocked = 0;
        $drivers = 0;

        User::query()->orderBy('id')->each(function (User $user) use (&$unlocked, &$drivers) {
            $unlocked += count(Progress::refresh($user, notify: (bool) $this->option('notify')));
            $drivers++;
        });

        $this->info("Checked {$drivers} drivers, {$unlocked} new achievements.");

        return self::SUCCESS;
    }
}
