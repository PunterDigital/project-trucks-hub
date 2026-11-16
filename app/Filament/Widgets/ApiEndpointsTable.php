<?php

namespace App\Filament\Widgets;

use App\Admin\ApiUsage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/** Each API endpoint over the last 7 days (#4667). */
class ApiEndpointsTable extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('Endpoints, last 7 days')
            ->description('Counted per hour by the API itself; no per-request records are kept. device.token answers 400 while the app waits for approval, so some errors there are normal polling.')
            ->records(fn (): array => collect(ApiUsage::byEndpoint(7))->keyBy('endpoint')->all())
            ->paginated(false)
            ->emptyStateHeading('No API traffic yet')
            ->columns([
                TextColumn::make('endpoint')->label('Endpoint')->fontFamily('mono'),
                TextColumn::make('requests')->label('Requests')->numeric(),
                TextColumn::make('bytes_in')->label('Received')->formatStateUsing(fn ($state): string => Number::fileSize((int) $state, 1)),
                TextColumn::make('throttled')->label('429s')->numeric(),
                TextColumn::make('errors')->label('Errors')->numeric(),
                TextColumn::make('jobs_synced')->label('Jobs synced')->numeric(),
                TextColumn::make('jobs_rejected')->label('Jobs refused')->numeric(),
            ]);
    }
}
