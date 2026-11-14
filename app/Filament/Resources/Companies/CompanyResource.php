<?php

namespace App\Filament\Resources\Companies;

use App\Enums\Recruitment;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Companies (VTCs) in the admin panel (#4664): read-only.
 */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    // The model binds by username/slug on the site; staff pages use the id (a Steam sign-up
    // may not have a username yet).
    protected static ?string $recordRouteKeyName = 'id';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('members.user')
            ->withCount(['members', 'deliveries', 'pendingApplications']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Company $record): string => '['.$record->tag.']')
                    ->searchable(['name', 'tag', 'slug'])
                    ->sortable(),
                TextColumn::make('owner')
                    ->state(fn (Company $record): ?string => self::ownerName($record))
                    ->placeholder('—'),
                TextColumn::make('recruitment')
                    ->badge()
                    ->formatStateUsing(fn (Recruitment $state): string => ucfirst($state->value)),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pending_applications_count')
                    ->label('Applications')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('deliveries_count')
                    ->label('Jobs')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Founded')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('recruitment')
                    ->options(collect(Recruitment::cases())->mapWithKeys(
                        fn (Recruitment $r): array => [$r->value => ucfirst($r->value)],
                    )->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Company')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('tag'),
                    TextEntry::make('slug'),
                    TextEntry::make('recruitment')
                        ->badge()
                        ->formatStateUsing(fn (Recruitment $state): string => ucfirst($state->value)),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('deliveries_count')->label('Jobs')->numeric(),
                    TextEntry::make('pending_applications_count')->label('Open applications')->numeric(),
                    TextEntry::make('created_at')->label('Founded')->dateTime(),
                ]),
            Section::make('Members')
                ->schema([
                    RepeatableEntry::make('members')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('user.name')->label('Driver'),
                            TextEntry::make('role')->badge(),
                            TextEntry::make('created_at')->label('Joined')->date(),
                        ]),
                ]),
        ]);
    }

    public static function publicPageAction(): Action
    {
        return Action::make('publicPage')
            ->label('Public page')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Company $record): string => route('companies.show', $record), shouldOpenInNewTab: true);
    }

    private static function ownerName(Company $company): ?string
    {
        return $company->members->first(fn ($m) => $m->role->value === 'owner')?->user?->name;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'view' => ViewCompany::route('/{record:id}'),
        ];
    }
}
