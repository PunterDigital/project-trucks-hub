<?php

namespace App\Filament\Widgets;

use App\Admin\QueueStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Jobs processed and failed per hour, last 48 hours (UTC) (#4666). */
class QueueThroughputChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Jobs per hour, last 48 hours (UTC)';

    protected ?string $maxHeight = '220px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $hourly = QueueStats::hourly(48);

        return [
            'datasets' => [
                ['label' => 'Processed', 'data' => $hourly['processed'], 'backgroundColor' => '#f59e0b', 'borderColor' => '#f59e0b'],
                ['label' => 'Failed', 'data' => $hourly['failed'], 'backgroundColor' => '#ef4444', 'borderColor' => '#ef4444'],
            ],
            'labels' => array_map(fn (string $hour): string => CarbonImmutable::parse($hour, 'UTC')->format('D H:00'), $hourly['hours']),
        ];
    }

    protected function getOptions(): array
    {
        $grid = ['color' => 'rgba(148, 163, 184, 0.15)'];

        return [
            'scales' => [
                'x' => ['stacked' => true, 'grid' => $grid],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => $grid],
            ],
        ];
    }
}
