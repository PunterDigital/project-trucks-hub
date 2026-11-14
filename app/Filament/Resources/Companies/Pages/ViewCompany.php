<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Admin\Moderation;
use App\Enums\CompanyRole;
use App\Filament\ModerationAction;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CompanyResource::publicPageAction(),
            ModerationAction::make(
                name: 'resetProfile',
                model: Company::class,
                label: 'Reset name and description',
                heading: 'Reset this company’s profile?',
                description: 'The name, tag and link become neutral ones (“Company 12”, “C12”) and the description is removed. The owner can choose new ones on the company’s Edit page.',
                done: 'Company profile reset',
                run: fn (Company $record, string $reason, ?User $staff) => Moderation::resetCompanyProfile($record, $reason, $staff),
            )->icon(Heroicon::OutlinedArrowPath),
            ModerationAction::make(
                name: 'removeMember',
                model: Company::class,
                label: 'Remove member',
                heading: 'Remove a member?',
                description: 'They leave the company at once. Their jobs stay on their profile. The owner can’t be removed here.',
                done: 'Member removed',
                color: 'warning',
                extraFields: [
                    Select::make('member')
                        ->label('Member')
                        ->required()
                        ->options(fn (Company $record): array => $record->members()
                            ->with('user')
                            ->where('role', '!=', CompanyRole::Owner->value)
                            ->get()
                            ->mapWithKeys(fn (CompanyMember $m): array => [$m->id => $m->user->name.' ('.$m->role->value.')'])
                            ->all()),
                ],
                runWithData: function (Company $record, array $data, string $reason, ?User $staff): void {
                    $member = $record->members()->whereKey((int) $data['member'])->first();
                    if ($member === null) {
                        throw new RuntimeException('That driver is no longer in this company.');
                    }
                    Moderation::removeMember($member, $reason, $staff);
                    $record->load('members.user');
                },
            )->icon(Heroicon::OutlinedUserMinus)
                ->visible(fn (Company $record): bool => $record->members()->where('role', '!=', CompanyRole::Owner->value)->exists()),
        ];
    }
}
