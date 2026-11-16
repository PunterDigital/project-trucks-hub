<?php

namespace App\Filament\Widgets;

use App\Admin\SchedulerStatus;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The scheduler's heartbeat, red once it is 10 minutes late (#4666). */
class SchedulerHeartbeat extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getStats(): array
    {
        $last = SchedulerStatus::lastBeat();

        if (! SchedulerStatus::isLate()) {
            return [
                Stat::make('Scheduler', 'Running')
                    ->description('Last heartbeat '.$last?->diffForHumans())
                    ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                    ->color('success'),
            ];
        }

        return [
            Stat::make('Scheduler', $last === null ? 'Never run here' : 'Not running')
                ->description(($last === null ? 'No heartbeat yet.' : 'Last heartbeat '.$last->diffForHumans().'.')
                    .' Check the server runs "php artisan schedule:run" every minute (Forge: Scheduler).')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
        ];
    }
}
