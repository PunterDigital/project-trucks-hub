<?php

namespace App\Console\Commands;

use App\Support\PostDrafts;
use Illuminate\Console\Command;

/**
 * Turns new repo post files (`resources/posts`) into blog drafts (#5153). Scheduled hourly;
 * the admin Blog posts list also runs it when it opens.
 */
class HubImportPostDrafts extends Command
{
    protected $signature = 'hub:import-post-drafts';

    protected $description = 'Create blog drafts from new files in resources/posts';

    public function handle(): int
    {
        $results = PostDrafts::import();

        if ($results === []) {
            $this->info('No new post files.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($results as $result) {
            $line = $result['file'].': '.$result['message'];
            if ($result['outcome'] === 'invalid') {
                $failed = true;
                $this->error($line);
            } else {
                $this->info($line);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
