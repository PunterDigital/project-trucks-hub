<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** The ten newest accounts (#4665). */
class RecentSignups extends TableWidget
{
    protected static ?int $sort = 8;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent sign-ups')
            ->query(fn (): Builder => User::query()->latest('created_at')->latest('id')->limit(10))
            ->paginated(false)
            ->recordUrl(fn (User $record): string => UserResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Driver')->description(fn (User $record): ?string => $record->username),
                TextColumn::make('via')
                    ->label('Via')
                    ->badge()
                    ->state(fn (User $record): string => $record->steam_id === null ? 'Email' : 'Steam')
                    ->color(fn (string $state): string => $state === 'Steam' ? 'info' : 'gray'),
                TextColumn::make('created_at')->label('Joined')->since()->dateTimeTooltip(),
            ]);
    }
}
