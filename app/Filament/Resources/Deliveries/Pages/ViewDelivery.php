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
                name: 'approveJob',
                model: Delivery::class,
                label: 'Approve',
                heading: 'Approve this job?',
                description: 'It shows on the Hub and counts towards stats and leaderboards, even though it was flagged. Re-syncs from the app don’t change it, and the Hub’s checks don’t run on it again.',
                done: 'Job approved',
                run: fn (Delivery $record, string $reason, ?User $staff) => Moderation::approveJob($record, $reason, $staff),
                color: 'success',
                reasonLabel: 'Note (goes in the audit log)',
            )->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn (Delivery $record): bool => in_array($record->reviewState(), [Delivery::REVIEW_PENDING, Delivery::REVIEW_REJECTED], true)),
            ModerationAction::make(
                name: 'rejectJob',
                model: Delivery::class,
                label: 'Reject',
                heading: 'Reject this job?',
                description: 'Only the driver still sees it, marked “Rejected by staff” with your reason. It counts nowhere, and re-syncs from the app don’t change it.',
                done: 'Job rejected',
                run: fn (Delivery $record, string $reason, ?User $staff) => Moderation::rejectJob($record, $reason, $staff),
                reasonLabel: 'Reason (shown to the driver)',
            )->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn (Delivery $record): bool => $record->review !== Delivery::REVIEW_REJECTED),
        ];
    }
}
