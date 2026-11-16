<?php

namespace App\Filament\Widgets;

use App\Admin\DatabaseStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/** Every table: rows, data and index size, growth over 7 and 30 days (#4666). */
class DatabaseTables extends TableWidget
{
    public function table(Table $table): Table
    {
        $bytes = fn (?int $value): string => $value === null ? '—' : Number::fileSize($value, 1);

        $growth = function (string $key): \Closure {
            return function (array $record) use ($key): string {
                $value = $record[$key];

                if ($value === null) {
                    return '—';
                }

                $sign = $value > 0 ? '+' : ($value < 0 ? '−' : '');
                $abs = abs($value);

                return $record['growth_unit'] === 'bytes'
                    ? $sign.Number::fileSize($abs, 1)
                    : $sign.Number::format($abs).' rows';
            };
        };

        return $table
            ->heading('Tables')
            ->description(DatabaseStats::isMysql()
                ? 'Rows are MySQL\'s estimate. Growth compares with the snapshot 7 and 30 days ago.'
                : 'Sizes are only known where SQLite has dbstat; growth is then in rows.')
            ->records(fn (): array => collect(DatabaseStats::tablesWithGrowth())->keyBy('name')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Table')->fontFamily('mono'),
                TextColumn::make('rows')->label('Rows')->numeric(),
                TextColumn::make('data_bytes')->label('Data')->formatStateUsing(fn ($state) => $bytes($state === null ? null : (int) $state))->placeholder('—'),
                TextColumn::make('index_bytes')->label('Indexes')->formatStateUsing(fn ($state) => $bytes($state === null ? null : (int) $state))->placeholder('—'),
                TextColumn::make('total_bytes')->label('Total')->formatStateUsing(fn ($state) => $bytes($state === null ? null : (int) $state))->placeholder('—'),
                TextColumn::make('growth_7')->label('7 days')->state($growth('growth_7')),
                TextColumn::make('growth_30')->label('30 days')->state($growth('growth_30')),
            ]);
    }
}
