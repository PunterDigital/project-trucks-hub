<?php

namespace App\Filament\Pages;

use App\Admin\DatabaseStats;
use App\Filament\Widgets\DatabaseOverview;
use App\Filament\Widgets\DatabaseSizeChart;
use App\Filament\Widgets\DatabaseTables;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Database health (#4666): size, connection, per-table rows and sizes with growth. Opening the
 * page stores today's snapshot if the scheduled `hub:db-snapshot` hasn't yet.
 */
class Database extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 100;

    protected static ?string $title = 'Database';

    public function mount(): void
    {
        DatabaseStats::snapshot();
    }

    protected function getHeaderWidgets(): array
    {
        return [DatabaseOverview::class, DatabaseSizeChart::class, DatabaseTables::class];
    }

    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }
}
