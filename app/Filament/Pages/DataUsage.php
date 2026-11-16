<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ApiEndpointsTable;
use App\Filament\Widgets\ApiTrafficChart;
use App\Filament\Widgets\DataUsageOverview;
use App\Filament\Widgets\DriverDataTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** ProjectTrucks API traffic and the data each driver has on the Hub (#4667). */
class DataUsage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 103;

    protected static ?string $title = 'Data usage';

    protected static ?string $slug = 'data-usage';

    protected function getHeaderWidgets(): array
    {
        return [DataUsageOverview::class, ApiTrafficChart::class, ApiEndpointsTable::class, DriverDataTable::class];
    }

    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }
}
