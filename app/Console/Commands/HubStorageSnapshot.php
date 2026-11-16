<?php

namespace App\Console\Commands;

use App\Admin\StorageStats;
use Illuminate\Console\Command;

/**
 * Stores today's storage snapshot for the admin Storage page (#4667). Scheduled daily; the
 * page also takes one when it opens on a day without one.
 */
class HubStorageSnapshot extends Command
{
    protected $signature = 'hub:storage-snapshot {--force : Replace today\'s snapshot}';

    protected $description = 'Store today\'s disk and storage use for the admin Storage page';

    public function handle(): int
    {
        $written = StorageStats::snapshot(force: (bool) $this->option('force'));

        $this->info($written ? 'Snapshot stored.' : 'Today already has a snapshot (use --force to replace it).');

        return self::SUCCESS;
    }
}
