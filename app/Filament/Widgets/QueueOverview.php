<?php

namespace App\Filament\Widgets;

use App\Admin\QueueStats;
use App\Models\FailedJob;
use Carbon\CarbonInterval;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/** Queue at a glance, with the "worker looks down" warning (#4666). */
class QueueOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $queues = QueueStats::waiting();
        $waiting = (int) array_sum(array_column($queues, 'waiting'));
        $running = (int) array_sum(array_column($queues, 'running'));
        $oldest = collect($queues)->pluck('oldest_seconds')->filter(fn ($s) => $s !== null)->max();
        $failed = FailedJob::query()->count();
        $day = QueueStats::lastDay();

        $worker = QueueStats::workerLooksDown()
            ? Stat::make('Worker', 'Looks down')
                ->description('Jobs have waited over '.QueueStats::STUCK_AFTER_MINUTES.' minutes. Is "php artisan queue:work" running (Forge: Daemons)?')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
            : Stat::make('Worker', $waiting + $running === 0 ? 'Idle' : 'Keeping up')
                ->description('Warns when a job waits over '.QueueStats::STUCK_AFTER_MINUTES.' minutes')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success');

        return [
            $worker,
            Stat::make('Waiting', Number::format($waiting))
                ->description($oldest === null
                    ? ($running > 0 ? $running.' running' : 'Nothing waiting')
                    : 'Oldest due '.CarbonInterval::seconds((int) $oldest)->cascade()->forHumans(['parts' => 1, 'short' => true]).' ago'.($running > 0 ? ", {$running} running" : '')),
            Stat::make('Failed jobs', Number::format($failed))
                ->description($failed === 0 ? 'None to look at' : 'Retry or forget them below')
                ->color($failed === 0 ? 'gray' : 'danger'),
            Stat::make('Last 24 hours', Number::format($day['processed']).' done')
                ->description(Number::format($day['failed']).' failed')
                ->color($day['failed'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
