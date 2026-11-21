<?php

namespace App\Support;

use App\Achievements\Progress;
use App\Enums\ApplicationStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Adding drivers to a company, shared by an open join, an accepted application and an invite
 * link (#5221), so every way in behaves the same.
 */
class CompanyRoster
{
    /**
     * Adds `$user` as a driver and withdraws their other pending applications; false when they
     * are already in a company.
     */
    public static function add(Company $company, User $user): bool
    {
        try {
            DB::transaction(function () use ($company, $user) {
                $company->members()->create(['user_id' => $user->id, 'role' => CompanyRole::Driver]);
                CompanyApplication::query()
                    ->where('user_id', $user->id)
                    ->where('status', ApplicationStatus::Pending)
                    ->where('company_id', '!=', $company->id)
                    ->update(['status' => ApplicationStatus::Withdrawn]);
            });
        } catch (QueryException) {
            // user_id is unique on company_members: they joined somewhere else meanwhile.
            return false;
        }

        // "Team player" (#4645).
        Progress::refresh($user->unsetRelation('membership'));

        return true;
    }

    /** Tells the owner and the company's Discord that `$user` joined without an application. */
    public static function announceJoin(Company $company, User $user): void
    {
        Notification::send(self::staffOf($company, CompanyRole::Owner), HubNotice::memberJoined($company, $user));
        DiscordWebhook::memberJoined($company, $user);
    }

    /**
     * The members at `$minimum` or above (Manager = owner and managers), as users.
     *
     * @return Collection<int, User>
     */
    public static function staffOf(Company $company, CompanyRole $minimum): Collection
    {
        $roles = $minimum === CompanyRole::Owner
            ? [CompanyRole::Owner]
            : [CompanyRole::Owner, CompanyRole::Manager];

        return User::query()
            ->whereIn('id', $company->members()->whereIn('role', $roles)->select('user_id'))
            ->get();
    }
}
