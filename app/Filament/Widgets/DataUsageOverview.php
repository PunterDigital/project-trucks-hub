<?php

namespace App\Filament\Widgets;

use App\Admin\ApiUsage;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/** The API over the last 24 hours (#4667). */
class DataUsageOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'ProjectTrucks API, last 24 hours';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $day = ApiUsage::totals(24);

        return [
            Stat::make('API requests', Number::format($day['requests']))
                ->description(Number::format($day['errors']).' answered with an error'),
            Stat::make('Received', Number::fileSize($day['bytes_in'], 1))
                ->description('Request bodies (jobs, live status)'),
            Stat::make('Jobs synced', Number::format($day['jobs_synced']))
                ->description(Number::format($day['jobs_flagged']).' Hub-flagged, '.Number::format($day['jobs_skipped']).' manual skipped, '.Number::format($day['jobs_rejected']).' refused'),
            Stat::make('Rate limited (429)', Number::format($day['throttled']))
                ->color($day['throttled'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
