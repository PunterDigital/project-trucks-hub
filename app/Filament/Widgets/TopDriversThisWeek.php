<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\UserResource;
use App\Models\Delivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** The ten drivers with the most counted km this week (Monday 00:00 UTC on, #4665). */
class TopDriversThisWeek extends TableWidget
{
    protected static ?int $sort = 6;

    /** @param Builder<Delivery> $q */
    public static function thisWeek(Builder $q): void
    {
        $q->counted()->where('deliveries.completed_at', '>=', CarbonImmutable::now('UTC')->startOfWeek());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Most active drivers this week')
            ->query(fn (): Builder => User::query()
                ->whereHas('deliveries', fn (Builder $q) => self::thisWeek($q))
                ->withCount(['deliveries as week_jobs' => fn (Builder $q) => self::thisWeek($q)])
                ->withSum(['deliveries as week_km' => fn (Builder $q) => self::thisWeek($q)], 'distance_km')
                ->orderByDesc('week_km')
                ->limit(10))
            ->paginated(false)
            ->emptyStateHeading('No counted jobs this week yet')
            ->recordUrl(fn (User $record): string => UserResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Driver')->description(fn (User $record): ?string => $record->username),
                TextColumn::make('week_jobs')->label('Jobs')->numeric(),
                TextColumn::make('week_km')->label('Distance')->numeric(0)->suffix(' km'),
            ]);
    }
}
