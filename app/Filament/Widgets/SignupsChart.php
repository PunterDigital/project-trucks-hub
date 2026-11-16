<?php

namespace App\Filament\Widgets;

class SignupsChart extends DailyChart
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Sign-ups per day';

    protected function lines(): array
    {
        return ['Email' => 'signups_email', 'Steam' => 'signups_steam'];
    }
}
