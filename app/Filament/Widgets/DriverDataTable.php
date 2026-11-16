<?php

namespace App\Filament\Widgets;

use App\Admin\ApiUsage;
use App\Filament\Resources\Users\UserResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/** The 20 drivers holding the most jobs, with a size estimate (#4667). */
class DriverDataTable extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('Data held per driver (top 20)')
            ->description('Size is the driver\'s share of the jobs table, where the database reports its size.')
            ->records(fn (): array => collect(ApiUsage::topDrivers(20))->keyBy('user_id')->all())
            ->paginated(false)
            ->emptyStateHeading('No synced jobs yet')
            ->columns([
                TextColumn::make('name')->label('Driver')
                    ->url(fn (array $record): string => UserResource::getUrl('view', ['record' => $record['user_id']])),
                TextColumn::make('username')->label('Username')->placeholder('—'),
                TextColumn::make('jobs')->label('Jobs')->numeric(),
                TextColumn::make('bytes')->label('Size (estimate)')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : Number::fileSize((int) $state, 1))
                    ->placeholder('—'),
            ]);
    }
}
