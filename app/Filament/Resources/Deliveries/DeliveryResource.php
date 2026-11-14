<?php

namespace App\Filament\Resources\Deliveries;

use App\Enums\DeliveryStatus;
use App\Filament\Resources\Deliveries\Pages\ListDeliveries;
use App\Filament\Resources\Deliveries\Pages\ViewDelivery;
use App\Models\Delivery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Synced jobs (`deliveries`) in the admin panel (#4664): read-only, with the filters staff
 * need to find one. "Flagged" means flagged by the app or by the Hub's own checks.
 */
class DeliveryResource extends Resource
{
    protected static ?string $model = Delivery::class;

    protected static ?string $slug = 'jobs';

    protected static ?string $modelLabel = 'job';

    protected static ?string $pluralModelLabel = 'jobs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'company']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('completed_at', 'desc')
            ->columns([
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Driver')
                    ->searchable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->placeholder('—'),
                TextColumn::make('route')
                    ->state(fn (Delivery $record): string => $record->source_city.' → '.$record->dest_city)
                    ->searchable(['source_city', 'dest_city']),
                TextColumn::make('cargo_name')
                    ->label('Cargo')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('distance_km')
                    ->label('Distance')
                    ->numeric(1)
                    ->suffix(' km')
                    ->sortable(),
                TextColumn::make('income')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryStatus $state): string => ucfirst(strtolower($state->value)))
                    ->color(fn (DeliveryStatus $state): string => match ($state) {
                        DeliveryStatus::Delivered => 'success',
                        DeliveryStatus::Late => 'warning',
                        DeliveryStatus::Cancelled => 'gray',
                    }),
                IconColumn::make('is_flagged')
                    ->label('Flagged')
                    ->state(fn (Delivery $record): bool => $record->isFlagged())
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),
                IconColumn::make('is_hidden')
                    ->label('Hidden')
                    // Not `$record->hidden` inside the model: that is Eloquent's $hidden array.
                    ->state(fn (Delivery $record): bool => (bool) $record->getAttribute('hidden'))
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray'),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Driver')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('company_id')
                    ->label('Company')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(collect(DeliveryStatus::cases())->mapWithKeys(
                        fn (DeliveryStatus $s): array => [$s->value => ucfirst(strtolower($s->value))],
                    )->all()),
                TernaryFilter::make('flagged')
                    ->label('Flagged')
                    ->queries(
                        true: fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('flagged', true)->orWhereNotNull('hub_flag_reason')),
                        false: fn (Builder $q) => $q->where('flagged', false)->whereNull('hub_flag_reason'),
                    ),
                TernaryFilter::make('hidden')
                    ->label('Hidden'),
                Filter::make('completed')
                    ->schema([
                        DatePicker::make('from')->label('Completed from'),
                        DatePicker::make('until')->label('Completed until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('completed_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, string $d) => $q->whereDate('completed_at', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'From '.$data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until '.$data['until'];
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Job')
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')->label('Driver'),
                    TextEntry::make('company.name')->label('Company')->placeholder('—'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('source_city')->label('From')
                        ->state(fn (Delivery $record): string => $record->source_city.' ('.$record->source_company.')'),
                    TextEntry::make('dest_city')->label('To')
                        ->state(fn (Delivery $record): string => $record->dest_city.' ('.$record->dest_company.')'),
                    TextEntry::make('cargo_name')->label('Cargo')
                        ->state(fn (Delivery $record): string => $record->cargo_name.', '.number_format($record->cargo_mass_kg / 1000, 1).' t'),
                    TextEntry::make('truck')->state(fn (Delivery $record): ?string => $record->truckName())->placeholder('—'),
                    TextEntry::make('distance_km')->label('Distance')->numeric(1)->suffix(' km'),
                    TextEntry::make('avg_speed_kmh')->label('Average speed')->numeric(1)->suffix(' km/h'),
                    TextEntry::make('income')->numeric(),
                    TextEntry::make('started_at')->label('Started')->dateTime(),
                    TextEntry::make('completed_at')->label('Completed')->dateTime(),
                    TextEntry::make('source')->label('Recorded by'),
                    TextEntry::make('client_id')->label('App job id')->copyable(),
                    TextEntry::make('updated_at')->label('Last synced')->since(),
                ]),
            Section::make('Checks')
                ->columns(3)
                ->schema([
                    TextEntry::make('flag_reason')->label('App flag')->placeholder('—'),
                    TextEntry::make('hub_flag_reason')->label('Hub flag')->placeholder('—'),
                    IconEntry::make('hub_held')->label('Held by the rate check')->boolean(),
                    IconEntry::make('is_hidden')->label('Hidden by the driver')
                        ->state(fn (Delivery $record): bool => (bool) $record->getAttribute('hidden'))
                        ->boolean(),
                ]),
        ]);
    }

    public static function publicPageAction(): Action
    {
        return Action::make('publicPage')
            ->label('Job page')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Delivery $record): string => route('jobs.show', $record), shouldOpenInNewTab: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveries::route('/'),
            'view' => ViewDelivery::route('/{record}'),
        ];
    }
}
