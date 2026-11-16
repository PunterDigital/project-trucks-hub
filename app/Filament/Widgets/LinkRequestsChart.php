<?php

namespace App\Filament\Widgets;

class LinkRequestsChart extends DailyChart
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'ProjectTrucks link requests per day';

    protected function lines(): array
    {
        return ['Started' => 'links_started', 'Approved' => 'links_approved', 'Expired' => 'links_expired'];
    }
}
