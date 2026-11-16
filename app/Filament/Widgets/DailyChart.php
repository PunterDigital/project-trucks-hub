<?php

namespace App\Filament\Widgets;

use App\Admin\DailyStats;
use App\Filament\Pages\Dashboard;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** A line chart of daily rollups over the dashboard's period (#4665). */
abstract class DailyChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '240px';

    /** Line colours that read in Filament's light and dark themes. */
    protected const COLOURS = ['#f59e0b', '#3b82f6', '#94a3b8'];

    /**
     * The lines to draw: label => metric.
     *
     * @return array<string, string>
     */
    abstract protected function lines(): array;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = DailyStats::series(Dashboard::days($this->pageFilters));
        $datasets = [];
        $i = 0;

        foreach ($this->lines() as $label => $metric) {
            $colour = self::COLOURS[$i++ % count(self::COLOURS)];
            $datasets[] = [
                'label' => $label,
                'data' => $series['values'][$metric],
                'borderColor' => $colour,
                'backgroundColor' => $colour,
                'tension' => 0.25,
                'pointRadius' => 2,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(
                fn (string $date): string => CarbonImmutable::parse($date)->format('M j'),
                $series['dates'],
            ),
        ];
    }

    protected function getOptions(): array
    {
        return [
            // A slate grid at low opacity reads in Filament's light and dark themes (Chart.js'
            // default grid is near-white on the dark panel).
            'scales' => [
                'x' => ['grid' => ['color' => 'rgba(148, 163, 184, 0.15)']],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => 'rgba(148, 163, 184, 0.15)']],
            ],
            'plugins' => ['legend' => ['display' => count($this->lines()) > 1]],
        ];
    }
}
