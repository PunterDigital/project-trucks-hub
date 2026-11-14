<?php

namespace App\Filament\Resources\Users;

use App\Admin\Audit;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Drivers in the admin panel (#4664): read-only, plus "Revoke linked apps".
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    // The model binds by username/slug on the site; staff pages use the id (a Steam sign-up
    // may not have a username yet).
    protected static ?string $recordRouteKeyName = 'id';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('membership.company')
            ->withCount(['deliveries', 'tokens'])
            ->withMax('deliveries', 'updated_at');
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
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['name', 'email', 'username', 'steam_id'])
                    ->sortable(),
                TextColumn::make('username')
                    ->placeholder('—'),
                TextColumn::make('steam_id')
                    ->label('SteamID')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('membership.company.name')
                    ->label('Company')
                    ->placeholder('—'),
                TextColumn::make('deliveries_count')
                    ->label('Jobs')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('tokens_count')
                    ->label('Linked apps')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('deliveries_max_updated_at')
                    ->label('Last sync')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date()
                    ->sortable(),
                IconColumn::make('is_staff')
                    ->label('Staff')
                    ->boolean()
                    ->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email'),
                    TextEntry::make('username')->placeholder('—'),
                    TextEntry::make('steam_id')->label('SteamID')->placeholder('—'),
                    TextEntry::make('membership.company.name')->label('Company')->placeholder('—'),
                    TextEntry::make('membership.role')->label('Role')->placeholder('—'),
                    IconEntry::make('profile_private')->label('Private profile')->boolean(),
                    IconEntry::make('is_staff')->label('Staff')->boolean(),
                    TextEntry::make('two_factor_confirmed_at')->label('Two-factor since')->dateTime()->placeholder('Off'),
                    TextEntry::make('created_at')->label('Joined')->dateTime(),
                ]),
            Section::make('ProjectTrucks')
                ->columns(3)
                ->schema([
                    TextEntry::make('deliveries_count')->label('Jobs')->numeric(),
                    TextEntry::make('tokens_count')->label('Linked apps')->numeric(),
                    TextEntry::make('deliveries_max_updated_at')->label('Last sync')->since()->placeholder('Never'),
                ]),
        ]);
    }

    /** The driver's public profile on the Hub. */
    public static function publicProfileAction(): Action
    {
        return Action::make('publicProfile')
            ->label('Public profile')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->visible(fn (User $record): bool => $record->username !== null)
            ->url(fn (User $record): string => route('drivers.show', $record->username), shouldOpenInNewTab: true);
    }

    /** Signs out every ProjectTrucks app linked to the account (Sanctum tokens). */
    public static function revokeLinkedAppsAction(): Action
    {
        return Action::make('revokeLinkedApps')
            ->label('Revoke linked apps')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Revoke all linked apps?')
            ->modalDescription('Every ProjectTrucks app linked to this account stops syncing until the driver links it again.')
            ->modalSubmitActionLabel('Revoke')
            ->action(function (User $record): void {
                $count = $record->tokens()->count();
                $record->tokens()->delete();
                Audit::record('user.linked_apps_revoked', $record, ['linked_apps' => $count], ['linked_apps' => 0]);

                Notification::make()
                    ->title($count === 1 ? '1 linked app revoked' : "{$count} linked apps revoked")
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record:id}'),
        ];
    }
}
