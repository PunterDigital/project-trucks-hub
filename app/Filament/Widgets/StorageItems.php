<?php

namespace App\Filament\Widgets;

use App\Admin\StorageStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/** Each measured folder and the database: files, size, growth over 30 days (#4667). */
class StorageItems extends TableWidget
{
    public function table(Table $table): Table
    {
        $files = config('cache.default') === 'file' && config('session.driver') === 'file'
            ? ''
            : ' The cache and sessions use the '.config('cache.default').' / '.config('session.driver').' store here, so their file folders stay small.';

        return $table
            ->heading('What takes the space')
            ->description('Growth compares with the snapshot 30 days ago.'.$files)
            ->records(fn (): array => collect(StorageStats::itemsWithGrowth())->keyBy('key')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('label')->label('Item'),
                TextColumn::make('files')->label('Files')->numeric()->placeholder('—'),
                TextColumn::make('bytes')->label('Size')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'Unknown' : Number::fileSize((int) $state, 1))
                    ->placeholder('Unknown'),
                TextColumn::make('growth_30')->label('30 days')->state(function (array $record): string {
                    $value = $record['growth_30'];

                    if ($value === null) {
                        return '—';
                    }

                    return ($value > 0 ? '+' : ($value < 0 ? '−' : '')).Number::fileSize(abs($value), 1);
                }),
            ]);
    }
}
