<?php

namespace App\Filament\Widgets;

class JobsSyncedChart extends DailyChart
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Jobs synced per day';

    protected function lines(): array
    {
        return ['Jobs' => 'jobs_synced'];
    }
}
