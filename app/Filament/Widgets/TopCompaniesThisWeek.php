<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** The ten companies with the most counted km this week (#4665). */
class TopCompaniesThisWeek extends TableWidget
{
    protected static ?int $sort = 7;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Most active companies this week')
            ->query(fn (): Builder => Company::query()
                ->whereHas('deliveries', fn (Builder $q) => TopDriversThisWeek::thisWeek($q))
                ->withCount(['deliveries as week_jobs' => fn (Builder $q) => TopDriversThisWeek::thisWeek($q)])
                ->withSum(['deliveries as week_km' => fn (Builder $q) => TopDriversThisWeek::thisWeek($q)], 'distance_km')
                ->orderByDesc('week_km')
                ->limit(10))
            ->paginated(false)
            ->emptyStateHeading('No counted company jobs this week yet')
            ->recordUrl(fn (Company $record): string => CompanyResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Company')->description(fn (Company $record): string => '['.$record->tag.']'),
                TextColumn::make('week_jobs')->label('Jobs')->numeric(),
                TextColumn::make('week_km')->label('Distance')->numeric(0)->suffix(' km'),
            ]);
    }
}
