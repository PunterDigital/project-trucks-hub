<?php

namespace App\Filament\Widgets;

use App\Admin\DatabaseStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** The whole database's size per daily snapshot, last 30 days (#4666). */
class DatabaseSizeChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Database size, last 30 days (MB)';

    protected ?string $maxHeight = '220px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = DatabaseStats::totalSeries(30);

        return [
            'datasets' => [[
                'label' => 'Size (MB)',
                'data' => array_map(fn (int $bytes): float => round($bytes / 1048576, 2), array_values($series)),
                'borderColor' => '#f59e0b',
                'backgroundColor' => '#f59e0b',
                'tension' => 0.25,
                'pointRadius' => 3,
            ]],
            'labels' => array_map(fn (string $date): string => CarbonImmutable::parse($date)->format('M j'), array_keys($series)),
        ];
    }

    protected function getOptions(): array
    {
        $grid = ['color' => 'rgba(148, 163, 184, 0.15)'];

        return [
            'scales' => ['x' => ['grid' => $grid], 'y' => ['beginAtZero' => true, 'grid' => $grid]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
