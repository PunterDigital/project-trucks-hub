<?php

namespace App\Console\Commands;

use App\Admin\DatabaseStats;
use Illuminate\Console\Command;

/**
 * Stores today's per-table database snapshot for the admin Database page (#4666). Scheduled
 * daily; the page also takes one when it opens on a day without one.
 */
class HubDbSnapshot extends Command
{
    protected $signature = 'hub:db-snapshot {--force : Replace today\'s snapshot}';

    protected $description = 'Store today\'s table sizes for the admin Database page';

    public function handle(): int
    {
        $written = DatabaseStats::snapshot(force: (bool) $this->option('force'));

        $this->info($written ? 'Snapshot stored.' : 'Today already has a snapshot (use --force to replace it).');

        return self::SUCCESS;
    }
}
