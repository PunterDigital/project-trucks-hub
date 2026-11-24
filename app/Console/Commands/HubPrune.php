<?php

namespace App\Console\Commands;

use App\Admin\Housekeeping;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * Daily housekeeping (#4667): expired link requests, stale live statuses, old log files, and
 * read bell notices over 90 days old (#5734). The first three are also buttons on the admin
 * Storage page.
 */
class HubPrune extends Command
{
    protected $signature = 'hub:prune';

    protected $description = 'Prune expired link requests, stale live statuses, old log files and old read notifications';

    public function handle(): int
    {
        $links = Housekeeping::pruneLinkRequests();
        $live = Housekeeping::pruneLiveStatuses();
        $logs = Housekeeping::clearOldLogs();
        $notices = Housekeeping::pruneReadNotifications();

        $this->info("Link requests pruned: {$links}");
        $this->info("Live statuses pruned: {$live}");
        $this->info("Read notifications pruned: {$notices}");
        $this->info("Log files deleted: {$logs['files']} (".Number::fileSize($logs['bytes'], 1).')');

        return self::SUCCESS;
    }
}
