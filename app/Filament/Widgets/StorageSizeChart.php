<?php

namespace App\Filament\Widgets;

use App\Admin\StorageStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Disk used, uploads, database and logs per daily snapshot, last 30 days (#4667). */
class StorageSizeChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Storage, last 30 days (MB)';

    protected ?string $maxHeight = '240px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = StorageStats::series(30);
        $mb = fn (?int $bytes): ?float => $bytes === null ? null : round($bytes / 1048576, 2);
        $line = fn (string $label, string $key, string $colour, bool $right = false): array => [
            'label' => $label,
            'data' => array_map(fn (array $day): ?float => $mb($day[$key]), array_values($series)),
            'borderColor' => $colour,
            'backgroundColor' => $colour,
            'tension' => 0.25,
            'pointRadius' => 3,
            'yAxisID' => $right ? 'disk' : 'y',
        ];

        return [
            'datasets' => [
                $line('Uploads', 'uploads', '#f59e0b'),
                $line('Database', 'database', '#3b82f6'),
                $line('Logs', 'logs', '#a855f7'),
                $line('Disk used (right axis)', 'disk_used', '#64748b', true),
            ],
            'labels' => array_map(fn (string $date): string => CarbonImmutable::parse($date)->format('M j'), array_keys($series)),
        ];
    }

    protected function getOptions(): array
    {
        $grid = ['color' => 'rgba(148, 163, 184, 0.15)'];

        return [
            'scales' => [
                'x' => ['grid' => $grid],
                'y' => ['beginAtZero' => true, 'grid' => $grid],
                'disk' => ['position' => 'right', 'beginAtZero' => true, 'grid' => ['display' => false]],
            ],
        ];
    }
}
