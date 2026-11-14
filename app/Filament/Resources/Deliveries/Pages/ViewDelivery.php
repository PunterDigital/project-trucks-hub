<?php

namespace App\Filament\Resources\Deliveries\Pages;

use App\Admin\Moderation;
use App\Filament\ModerationAction;
use App\Filament\Resources\Deliveries\DeliveryResource;
use App\Models\Delivery;
use App\Models\User;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDelivery extends ViewRecord
{
    protected static string $resource = DeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeliveryResource::publicPageAction(),
            ModerationAction::make(
                name: 'hideJob',
                model: Delivery::class,
                label: 'Hide job',
                heading: 'Hide this job?',
                description: 'Only the driver still sees it (with a note that staff hid it). It stops counting towards stats and leaderboards, and the driver can’t show it again.',
                done: 'Job hidden',
                run: fn (Delivery $record, string $reason, ?User $staff) => Moderation::hideJob($record, $reason, $staff),
                color: 'warning',
            )->icon(Heroicon::OutlinedEyeSlash)
                ->visible(fn (Delivery $record): bool => ! $record->staff_hidden),
            ModerationAction::make(
                name: 'unhideJob',
                model: Delivery::class,
                label: 'Unhide job',
                heading: 'Show this job again?',
                description: 'It shows on the Hub again (unless the driver hid it themselves) and counts again if nothing else stops it.',
                done: 'Job shown again',
                run: fn (Delivery $record, string $reason, ?User $staff) => Moderation::unhideJob($record, $reason, $staff),
                color: 'gray',
            )->icon(Heroicon::OutlinedEye)
                ->visible(fn (Delivery $record): bool => $record->staff_hidden),
            ModerationAction::make(
                name: 'flagJob',
                model: Delivery::class,
                label: 'Flag as cheated',
                heading: 'Flag this job as cheated?',
                description: 'It stays on the driver’s profile with a Flagged badge and your reason, but no longer counts towards stats or leaderboards. Re-syncs from the app don’t change it.',
                done: 'Job flagged',
                run: fn (Delivery $record, string $reason, ?User $staff) => Moderation::flagJob($record, $reason, $staff),
                reasonLabel: 'Reason (shown on the job page)',
            )->icon(Heroicon::OutlinedFlag)
                ->visible(fn (Delivery $record): bool => ! str_starts_with((string) $record->hub_flag_reason, Moderation::STAFF_FLAG_PREFIX)),
            ModerationAction::make(
                name: 'clearFlag',
                model: Delivery::class,
                label: 'Clear Hub flag',
                heading: 'Clear the Hub’s flag?',
                description: 'The job counts again (unless the app flagged it too), and re-syncs no longer run the Hub’s checks on it.',
                done: 'Flag cleared',
                run: fn (Delivery $record, string $reason, ?User $staff) => Moderation::clearJobFlag($record, $reason, $staff),
                color: 'gray',
            )->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn (Delivery $record): bool => $record->hub_flag_reason !== null),
        ];
    }
}
