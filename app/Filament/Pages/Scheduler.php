<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ScheduledTasks;
use App\Filament\Widgets\SchedulerHeartbeat;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Whether Laravel's scheduler runs on this server, and each task's last run (#4666). */
class Scheduler extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 110;

    protected static ?string $title = 'Scheduler';

    protected function getHeaderWidgets(): array
    {
        return [SchedulerHeartbeat::class, ScheduledTasks::class];
    }

    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }
}
