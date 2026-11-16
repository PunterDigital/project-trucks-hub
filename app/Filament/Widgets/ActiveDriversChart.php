<?php

namespace App\Filament\Widgets;

class ActiveDriversChart extends DailyChart
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Active drivers per day';

    protected function lines(): array
    {
        return ['Drivers' => 'active_drivers'];
    }
}
