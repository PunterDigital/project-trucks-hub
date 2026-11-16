<?php

namespace App\Filament\Widgets;

use App\Admin\ApiUsage;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** API requests, live-status updates and 429s per UTC day, last 30 days (#4667). */
class ApiTrafficChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'API requests per day, last 30 days (UTC)';

    protected ?string $maxHeight = '240px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = ApiUsage::daily(30);
        $line = fn (string $label, string $key, string $colour): array => [
            'label' => $label,
            'data' => array_column(array_values($series), $key),
            'borderColor' => $colour,
            'backgroundColor' => $colour,
            'tension' => 0.25,
            'pointRadius' => 2,
        ];

        return [
            'datasets' => [
                $line('All requests', 'requests', '#f59e0b'),
                $line('Live-status updates', 'status_updates', '#3b82f6'),
                $line('Rate limited (429)', 'throttled', '#ef4444'),
            ],
            'labels' => array_map(fn (string $date): string => CarbonImmutable::parse($date)->format('M j'), array_keys($series)),
        ];
    }

    protected function getOptions(): array
    {
        $grid = ['color' => 'rgba(148, 163, 184, 0.15)'];

        return ['scales' => ['x' => ['grid' => $grid], 'y' => ['beginAtZero' => true, 'grid' => $grid]]];
    }
}
