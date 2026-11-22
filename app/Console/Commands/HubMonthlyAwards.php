<?php

namespace App\Console\Commands;

use App\Support\CompanyAwards;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Driver of the month (#5226). The scheduler runs it on the 1st for the month that just
 * ended; `--month=YYYY-MM` awards an earlier one. Companies that already have that month's
 * awards are skipped, so running it again is safe.
 */
class HubMonthlyAwards extends Command
{
    protected $signature = 'hub:monthly-awards {--month= : A finished UTC month, YYYY-MM (default: last month)}';

    protected $description = "Give every company's top three drivers of a month their Driver of the month award";

    public function handle(): int
    {
        $option = $this->option('month');
        $month = is_string($option) && $option !== '' ? $option : CompanyAwards::lastMonth();

        try {
            $awarded = CompanyAwards::award($month);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Awarded {$month}: {$awarded} companies.");

        return self::SUCCESS;
    }
}
