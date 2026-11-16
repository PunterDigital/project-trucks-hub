<?php

namespace App\Filament\Resources\Reports;

use App\Admin\Moderation;
use App\Enums\ReportReason;
use App\Filament\ModerationAction;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Deliveries\DeliveryResource;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\Report;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
 * The Reports queue (#4651): what drivers reported, the item inline, and "Resolve" with a note.
 * The moderation buttons themselves live on the item's own admin page ("Open in admin").
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['reporter', 'resolver', 'reportable']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Report::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Reported')
                    ->since()
                    ->sortable(),
                TextColumn::make('type')
                    ->state(fn (Report $record): string => $record->typeLabel())
                    ->badge()
                    ->color('gray'),
                TextColumn::make('item')
                    ->state(fn (Report $record): string => $record->itemLabel())
                    ->wrap(),
                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (ReportReason $state): string => $state->label())
                    ->color(fn (ReportReason $state): string => $state === ReportReason::Cheating ? 'warning' : 'danger'),
                TextColumn::make('details')
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('reporter.name')
                    ->label('Reported by'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === Report::OPEN ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->options(ReportReason::options()),
                SelectFilter::make('reportable_type')
                    ->label('Type')
                    ->options([
                        User::class => 'Driver',
                        Company::class => 'Company',
                        Delivery::class => 'Job',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Report')
                ->columns(3)
                ->schema([
                    TextEntry::make('reason')
                        ->badge()
                        ->formatStateUsing(fn (ReportReason $state): string => $state->label()),
                    TextEntry::make('reporter.name')->label('Reported by'),
                    TextEntry::make('created_at')->label('Reported')->dateTime(),
                    TextEntry::make('details')->placeholder('No details given')->columnSpanFull(),
                ]),
            Section::make(fn (Report $record): string => 'Reported '.strtolower($record->typeLabel()))
                ->columns(3)
                ->schema(fn (Report $record): array => self::itemEntries($record)),
            Section::make('Resolution')
                ->visible(fn (Report $record): bool => ! $record->isOpen())
                ->columns(3)
                ->schema([
                    TextEntry::make('resolver.name')->label('Resolved by')->placeholder('—'),
                    TextEntry::make('resolved_at')->label('Resolved')->dateTime(),
                    TextEntry::make('resolution_note')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * The reported item, inline, so staff can judge the report without leaving the queue.
     *
     * @return array<int, TextEntry>
     */
    private static function itemEntries(Report $record): array
    {
        $item = $record->reportable;

        return match (true) {
            $item instanceof User => [
                TextEntry::make('item_name')->label('Name')->state($item->name),
                TextEntry::make('item_username')->label('Username')->state($item->username)->placeholder('—'),
                TextEntry::make('item_suspended')->label('Suspended')
                    ->state($item->suspended_at?->toDayDateTimeString())->placeholder('No'),
            ],
            $item instanceof Company => [
                TextEntry::make('item_name')->label('Name')->state($item->name),
                TextEntry::make('item_tag')->label('Tag')->state($item->tag),
                TextEntry::make('item_members')->label('Members')->state($item->members()->count()),
                TextEntry::make('item_description')->label('Description')->state($item->description)
                    ->placeholder('—')->columnSpanFull(),
            ],
            $item instanceof Delivery => [
                TextEntry::make('item_route')->label('Route')->state($item->source_city.' → '.$item->dest_city),
                TextEntry::make('item_driver')->label('Driver')->state($item->user->name),
                TextEntry::make('item_cargo')->label('Cargo')->state($item->cargo_name),
                TextEntry::make('item_distance')->label('Distance')->state(number_format($item->distance_km, 1).' km'),
                TextEntry::make('item_speed')->label('Average speed')->state(number_format($item->avg_speed_kmh, 1).' km/h'),
                TextEntry::make('item_flag')->label('Flags')->state(implode('; ', array_filter([
                    $item->flagged ? 'App: '.$item->flag_reason : null,
                    $item->hub_flag_reason !== null ? 'Hub: '.$item->hub_flag_reason : null,
                    $item->staff_hidden ? 'Hidden by staff' : null,
                    match ($item->reviewState()) {
                        Delivery::REVIEW_PENDING => 'Pending review',
                        Delivery::REVIEW_APPROVED => 'Approved by staff',
                        Delivery::REVIEW_REJECTED => 'Rejected by staff: '.$item->review_note,
                        default => null,
                    },
                ])))->placeholder('None'),
            ],
            default => [
                TextEntry::make('item_gone')->hiddenLabel()->state('The reported item has been deleted since.'),
            ],
        };
    }

    /** Opens the reported item's admin page, where the moderation buttons are. */
    public static function openItemAction(): Action
    {
        return Action::make('openItem')
            ->label('Open in admin')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->visible(fn (Report $record): bool => $record->reportable !== null)
            ->url(fn (Report $record): ?string => match (true) {
                $record->reportable instanceof User => UserResource::getUrl('view', ['record' => $record->reportable]),
                $record->reportable instanceof Company => CompanyResource::getUrl('view', ['record' => $record->reportable]),
                $record->reportable instanceof Delivery => DeliveryResource::getUrl('view', ['record' => $record->reportable]),
                default => null,
            });
    }

    public static function resolveAction(): Action
    {
        return ModerationAction::make(
            name: 'resolve',
            model: Report::class,
            label: 'Resolve',
            heading: 'Resolve this report?',
            description: 'Say what you did (or why nothing needed doing). The reporter isn’t told.',
            done: 'Report resolved',
            run: fn (Report $record, string $note, ?User $staff) => Moderation::resolveReport($record, $note, $staff),
            color: 'success',
            reasonLabel: 'Note',
        )->icon(Heroicon::OutlinedCheck)
            ->visible(fn (Report $record): bool => $record->isOpen());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'view' => ViewReport::route('/{record}'),
        ];
    }
}
