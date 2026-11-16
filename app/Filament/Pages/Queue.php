<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FailedJobsTable;
use App\Filament\Widgets\JobRuntimesTable;
use App\Filament\Widgets\QueueOverview;
use App\Filament\Widgets\QueueThroughputChart;
use App\Filament\Widgets\QueueWaitingTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The Laravel queue (#4666): waiting and failed jobs, throughput, run times, worker health. */
class Queue extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 105;

    protected static ?string $title = 'Queue';

    protected function getHeaderWidgets(): array
    {
        return [
            QueueOverview::class,
            QueueThroughputChart::class,
            QueueWaitingTable::class,
            FailedJobsTable::class,
            JobRuntimesTable::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }
}
