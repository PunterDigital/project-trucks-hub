<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Deliveries\DeliveryResource;
use App\Models\Delivery;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** The ten newest synced jobs with an app flag or a Hub (anti-cheat or staff) flag (#4665). */
class RecentlyFlaggedJobs extends TableWidget
{
    protected static ?int $sort = 9;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recently flagged jobs')
            ->query(fn (): Builder => Delivery::query()
                ->with('user')
                ->where(fn (Builder $q) => $q->where('flagged', true)->orWhereNotNull('hub_flag_reason'))
                ->latest('created_at')
                ->latest('id')
                ->limit(10))
            ->paginated(false)
            ->emptyStateHeading('No flagged jobs')
            ->recordUrl(fn (Delivery $record): string => DeliveryResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('user.name')->label('Driver'),
                TextColumn::make('route')
                    ->state(fn (Delivery $record): string => $record->source_city.' → '.$record->dest_city),
                TextColumn::make('flag')
                    ->label('Flag')
                    ->badge()
                    ->state(fn (Delivery $record): string => $record->hub_flag_reason !== null ? 'Hub' : 'App')
                    ->color(fn (string $state): string => $state === 'Hub' ? 'danger' : 'warning')
                    ->description(fn (Delivery $record): ?string => $record->hub_flag_reason ?? $record->flag_reason),
                TextColumn::make('review_state')
                    ->label('Review')
                    ->badge()
                    ->state(fn (Delivery $record): string => DeliveryResource::reviewLabel($record->reviewState()))
                    ->color(fn (Delivery $record): string => DeliveryResource::reviewColor($record->reviewState())),
                TextColumn::make('created_at')->label('Synced')->since()->dateTimeTooltip(),
            ]);
    }
}
