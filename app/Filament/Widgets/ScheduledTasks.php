<?php

namespace App\Filament\Widgets;

use App\Admin\SchedulerStatus;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The last run of each scheduled task that has run on this server (#4666). */
class ScheduledTasks extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('Last runs')
            ->records(fn (): array => collect(SchedulerStatus::runs())->sortBy('task')->all())
            ->paginated(false)
            ->emptyStateHeading('No scheduled task has run on this server yet')
            ->columns([
                TextColumn::make('task')->label('Task')->fontFamily('mono'),
                TextColumn::make('schedule')->label('Cron')->fontFamily('mono'),
                TextColumn::make('ran_at')
                    ->label('Last run')
                    ->formatStateUsing(fn ($state): string => CarbonImmutable::createFromTimestamp((int) $state)->diffForHumans())
                    ->tooltip(fn (array $record): string => CarbonImmutable::createFromTimestamp((int) $record['ran_at'])->toDateTimeString().' UTC'),
                TextColumn::make('result')
                    ->label('Result')
                    ->badge()
                    ->state(fn (array $record): string => $record['ok'] ? 'OK' : 'Failed')
                    ->color(fn (string $state): string => $state === 'OK' ? 'success' : 'danger'),
                TextColumn::make('runtime')->label('Run time')->suffix(' s')->placeholder('—'),
            ]);
    }
}
