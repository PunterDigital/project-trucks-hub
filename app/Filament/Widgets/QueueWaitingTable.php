<?php

namespace App\Filament\Widgets;

use App\Admin\QueueStats;
use Carbon\CarbonInterval;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Jobs in the database queue per queue name (#4666). */
class QueueWaitingTable extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('Waiting per queue')
            ->records(fn (): array => collect(QueueStats::waiting())->keyBy('queue')->all())
            ->paginated(false)
            ->emptyStateHeading('The queue is empty')
            ->columns([
                TextColumn::make('queue')->label('Queue')->fontFamily('mono'),
                TextColumn::make('waiting')->label('Waiting')->numeric(),
                TextColumn::make('running')->label('Running')->numeric(),
                TextColumn::make('oldest_seconds')
                    ->label('Oldest due')
                    ->formatStateUsing(fn ($state): string => CarbonInterval::seconds((int) $state)->cascade()->forHumans(['parts' => 2, 'short' => true]).' ago')
                    ->color(fn ($state): ?string => $state !== null && (int) $state > QueueStats::STUCK_AFTER_MINUTES * 60 ? 'danger' : null)
                    ->placeholder('—'),
            ]);
    }
}
