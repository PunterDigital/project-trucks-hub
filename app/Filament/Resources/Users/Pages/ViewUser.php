<?php

namespace App\Filament\Resources\Users\Pages;

use App\Admin\Moderation;
use App\Filament\ModerationAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::publicProfileAction(),
            UserResource::revokeLinkedAppsAction(),
            ModerationAction::make(
                name: 'suspend',
                model: User::class,
                label: 'Suspend',
                heading: 'Suspend this account?',
                description: 'They are signed out everywhere and can’t sign in, their linked apps stop syncing, and their profile, jobs and live status disappear from every public page until you lift the suspension.',
                done: 'Account suspended',
                run: fn (User $record, string $reason, ?User $staff) => Moderation::suspend($record, $reason, $staff),
                reasonLabel: 'Reason (goes in the audit log, not shown to the driver)',
            )->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn (User $record): bool => ! $record->isSuspended()),
            ModerationAction::make(
                name: 'liftSuspension',
                model: User::class,
                label: 'Lift suspension',
                heading: 'Lift the suspension?',
                description: 'They can sign in again and their profile and jobs come back. Linked apps stay revoked: they link ProjectTrucks again.',
                done: 'Suspension lifted',
                run: fn (User $record, string $reason, ?User $staff) => Moderation::liftSuspension($record, $reason, $staff),
                color: 'warning',
            )->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn (User $record): bool => $record->isSuspended()),
        ];
    }
}
