<?php

namespace App\Console\Commands;

use App\Admin\DailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Stores the admin dashboard's daily rollups (#4665). The scheduler runs it just after
 * midnight UTC for the day that closed; with no options it fills every closed day that has
 * no rows yet, so it doubles as the backfill. `--force` works the range out again.
 */
class HubDailyStats extends Command
{
    protected $signature = 'hub:daily-stats
        {--from= : First UTC day (Y-m-d); default: the first account}
        {--to= : Last UTC day (Y-m-d); default and latest: yesterday}
        {--force : Recompute days that are already stored}';

    protected $description = 'Store daily platform stats for the admin dashboard';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if ($value !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                $this->error("--{$name} must be a date like 2026-11-14.");

                return self::INVALID;
            }
        }

        $written = DailyStats::fill(
            $from === null ? null : CarbonImmutable::parse((string) $from, 'UTC'),
            $to === null ? null : CarbonImmutable::parse((string) $to, 'UTC'),
            (bool) $this->option('force'),
        );

        $this->info("Stored {$written} ".($written === 1 ? 'day' : 'days').'.');

        return self::SUCCESS;
    }
}
