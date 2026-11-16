<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ActiveDriversChart;
use App\Filament\Widgets\JobsSyncedChart;
use App\Filament\Widgets\LinkRequestsChart;
use App\Filament\Widgets\PlatformStatsOverview;
use App\Filament\Widgets\RecentlyFlaggedJobs;
use App\Filament\Widgets\RecentSignups;
use App\Filament\Widgets\SignupsChart;
use App\Filament\Widgets\TopCompaniesThisWeek;
use App\Filament\Widgets\TopDriversThisWeek;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * The admin home page (#4665): how the platform is doing. Numbers come from
 * `App\Admin\DailyStats` (stored daily rollups, today live). The period picker drives the
 * stat cards and charts; the tables are fixed ("this week", latest).
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    /** @var array<int, string> */
    public const PERIODS = [
        7 => 'Last 7 days',
        30 => 'Last 30 days',
        90 => 'Last 90 days',
    ];

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('days')
                ->label('Period')
                ->options(self::PERIODS)
                ->default(7)
                ->selectablePlaceholder(false),
        ]);
    }

    /**
     * The picked period in days; anything unexpected falls back to 7.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function days(?array $filters): int
    {
        $days = (int) ($filters['days'] ?? 7);

        return array_key_exists($days, self::PERIODS) ? $days : 7;
    }

    public function getWidgets(): array
    {
        return [
            PlatformStatsOverview::class,
            SignupsChart::class,
            JobsSyncedChart::class,
            ActiveDriversChart::class,
            LinkRequestsChart::class,
            TopDriversThisWeek::class,
            TopCompaniesThisWeek::class,
            RecentSignups::class,
            RecentlyFlaggedJobs::class,
        ];
    }
}
