<?php

namespace App\Filament\Widgets;

use App\Admin\QueueStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Runs and average run time per job class, last 7 days (#4666). */
class JobRuntimesTable extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('Run times, last 7 days')
            ->records(fn (): array => collect(QueueStats::runtimes(7))->keyBy('job_class')->all())
            ->paginated(false)
            ->emptyStateHeading('No queued job has run in the last 7 days')
            ->columns([
                TextColumn::make('job_class')->label('Job')->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->tooltip(fn (array $record): string => $record['job_class']),
                TextColumn::make('runs')->label('Runs')->numeric(),
                TextColumn::make('failed')->label('Failed')->numeric(),
                TextColumn::make('avg_ms')->label('Average')->numeric()->suffix(' ms'),
            ]);
    }
}
