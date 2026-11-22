<?php

namespace App\Console\Commands;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Notifications\HubNotice;
use App\Support\CompanyActivity;
use App\Support\CompanyRoster;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Monthly activity requirement (#5225). The scheduler runs it on the 1st: each company with a
 * minimum tells its owner and managers, in one bell notice, who missed last month. Nobody is
 * removed. `companies.activity_checked_month` keeps a second run from sending it again.
 */
class HubActivityCheck extends Command
{
    protected $signature = 'hub:activity-check';

    protected $description = "Tell company owners and managers who missed last month's activity minimum";

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $lastMonth = $now->startOfMonth()->subMonthNoOverflow();
        $key = $lastMonth->format('Y-m');
        $label = $lastMonth->format('F Y');
        $notified = 0;

        Company::query()
            ->where(fn ($q) => $q->whereNotNull('activity_min_km')->orWhereNotNull('activity_min_deliveries'))
            ->where(fn ($q) => $q->whereNull('activity_checked_month')->orWhere('activity_checked_month', '<', $key))
            ->orderBy('id')
            ->each(function (Company $company) use ($now, $key, $label, &$notified) {
                $missed = CompanyActivity::missedLastMonth($company, $now);

                if ($missed->isNotEmpty()) {
                    Notification::send(
                        CompanyRoster::staffOf($company, CompanyRole::Manager),
                        HubNotice::activityMissed($company, $label, array_values($missed->map(fn (CompanyMember $m) => $m->user->name)->all())),
                    );
                    $notified++;
                }

                $company->forceFill(['activity_checked_month' => $key])->save();
            });

        $this->info("Checked {$key}: {$notified} companies notified.");

        return self::SUCCESS;
    }
}
