<?php

namespace App\Filament\Pages;

use App\Admin\Audit;
use App\Admin\Housekeeping;
use App\Admin\StorageStats;
use App\Filament\Widgets\StorageItems;
use App\Filament\Widgets\StorageOverview;
use App\Filament\Widgets\StorageSizeChart;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Disk and storage use (#4667): the server disk, uploads, logs, file caches and the database,
 * with 30-day charts and the housekeeping buttons. Opening the page stores today's snapshot if
 * the scheduled `hub:storage-snapshot` hasn't yet.
 */
class Storage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 102;

    protected static ?string $title = 'Storage';

    protected static ?string $slug = 'storage';

    public static function getNavigationBadge(): ?string
    {
        return StorageStats::disk()['low'] ? 'Low' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Free disk space is under 10% or 5 GB';
    }

    public function mount(): void
    {
        StorageStats::snapshot();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pruneLinkRequests')
                ->label('Prune link requests')
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Prune expired link requests?')
                ->modalDescription('Deletes app link requests that expired more than a day ago. Linked apps are not touched.')
                ->action(function (): void {
                    $deleted = Housekeeping::pruneLinkRequests();
                    Audit::record('housekeeping.link_requests_pruned', after: ['deleted' => $deleted]);
                    Notification::make()->title("Link requests pruned: {$deleted}")->success()->send();
                }),
            Action::make('pruneLiveStatuses')
                ->label('Prune live statuses')
                ->icon(Heroicon::OutlinedSignalSlash)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Prune stale live statuses?')
                ->modalDescription('Deletes live statuses the app hasn\'t updated for '.Housekeeping::LIVE_STATUS_STALE_HOURS.' hours. Drivers on the road now are not touched.')
                ->action(function (): void {
                    $deleted = Housekeeping::pruneLiveStatuses();
                    Audit::record('housekeeping.live_statuses_pruned', after: ['deleted' => $deleted]);
                    Notification::make()->title("Live statuses pruned: {$deleted}")->success()->send();
                }),
            Action::make('clearOldLogs')
                ->label('Clear old logs')
                ->icon(Heroicon::OutlinedDocumentMinus)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Clear old log files?')
                ->modalDescription('Deletes log files last written more than '.Housekeeping::LOG_KEEP_DAYS.' days ago. Today\'s log stays.')
                ->action(function (): void {
                    $cleared = Housekeeping::clearOldLogs();
                    Audit::record('housekeeping.logs_cleared', after: $cleared);
                    Notification::make()
                        ->title("Log files deleted: {$cleared['files']}")
                        ->body(Number::fileSize($cleared['bytes'], 1).' freed')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [StorageOverview::class, StorageSizeChart::class, StorageItems::class];
    }

    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }
}
