<?php

namespace App\Http\Controllers;

use App\Achievements\Progress;
use App\Enums\ApplicationStatus;
use App\Enums\CompanyRole;
use App\Enums\Recruitment;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\CompanyMember;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

/**
 * Joining, applying, leaving and running a company's roster.
 *
 * Owner: everything, including roles and handing over ownership. Manager: recruitment and
 * removing drivers. Driver: can leave. A driver is in one company at a time.
 */
class CompanyMembershipController extends Controller
{
    public function join(Request $request, Company $company): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($company->recruitment !== Recruitment::Open) {
            return $this->fail($company, __('This company only takes applications.'));
        }
        if ($user->membership !== null) {
            return $this->fail($company, __('Leave your current company first.'));
        }

        if (! $this->addMember($company, $user)) {
            return $this->fail($company, __('Leave your current company first.'));
        }

        Notification::send($this->staffOf($company, CompanyRole::Owner), HubNotice::memberJoined($company, $user));

        return $this->done($company, __('Welcome to :name!', ['name' => $company->name]));
    }

    public function apply(Request $request, Company $company): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);

        if ($company->recruitment !== Recruitment::Apply) {
            return $this->fail($company, __('This company is not taking applications.'));
        }
        if ($user->membership !== null) {
            return $this->fail($company, __('Leave your current company first.'));
        }

        $application = $company->applications()->firstOrCreate(
            ['user_id' => $user->id, 'status' => ApplicationStatus::Pending],
            ['message' => $data['message'] ?? null],
        );

        // A second click on Apply finds the pending one: owners hear about it once.
        if ($application->wasRecentlyCreated) {
            Notification::send(
                $this->staffOf($company, CompanyRole::Manager),
                HubNotice::applicationReceived($company, $user, $data['message'] ?? null),
            );
        }

        return $this->done($company, __('Application sent. The company will get back to you.'));
    }

    public function withdraw(Request $request, Company $company): RedirectResponse
    {
        $company->applications()
            ->where('user_id', $request->user()->id)
            ->where('status', ApplicationStatus::Pending)
            ->update(['status' => ApplicationStatus::Withdrawn]);

        return $this->done($company, __('Application withdrawn.'));
    }

    public function decide(Request $request, Company $company, CompanyApplication $application): RedirectResponse
    {
        $this->authorizeRole($request, $company, CompanyRole::Manager);
        abort_unless($application->company_id === $company->id, 404);

        $data = $request->validate(['decision' => ['required', 'in:accept,decline']]);

        if ($application->status !== ApplicationStatus::Pending) {
            return $this->fail($company, __('That application has already been handled.'));
        }

        $decided = ['decided_by' => $request->user()->id, 'decided_at' => now()];

        if ($data['decision'] === 'decline') {
            $application->update([...$decided, 'status' => ApplicationStatus::Declined]);
            $application->user->notify(HubNotice::applicationDecided($company, false));

            return $this->done($company, __('Application declined.'));
        }

        if (! $this->addMember($company, $application->user)) {
            $application->update(['status' => ApplicationStatus::Withdrawn]);

            return $this->fail($company, __(':name has joined another company.', ['name' => $application->user->name]));
        }
        $application->update([...$decided, 'status' => ApplicationStatus::Accepted]);
        $application->user->notify(HubNotice::applicationDecided($company, true));

        return $this->done($company, __(':name joined the company.', ['name' => $application->user->name]));
    }

    public function leave(Request $request, Company $company): RedirectResponse
    {
        $role = $company->roleOf($request->user());
        abort_if($role === null, 403);

        if ($role === CompanyRole::Owner) {
            return $this->fail($company, $company->members()->count() > 1
                ? __('Hand over ownership before you leave.')
                : __('You are the only member: delete the company instead.'));
        }

        $request->user()->membership->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left :name.', ['name' => $company->name])]);

        return to_route('companies.show', $company);
    }

    public function update(Request $request, Company $company, CompanyMember $member): RedirectResponse
    {
        $this->authorizeRole($request, $company, CompanyRole::Owner);
        abort_unless($member->company_id === $company->id, 404);

        $data = $request->validate(['role' => ['required', 'in:owner,manager,driver']]);

        if ($member->user_id === $request->user()->id) {
            return $this->fail($company, __('Hand ownership to someone else to change your own role.'));
        }

        if ($data['role'] === CompanyRole::Owner->value) {
            DB::transaction(function () use ($request, $member) {
                $request->user()->membership->update(['role' => CompanyRole::Manager]);
                $member->update(['role' => CompanyRole::Owner]);
            });
            $member->user->notify(HubNotice::ownershipHandedOver($company, $request->user()));

            return $this->done($company, __(':name is now the owner.', ['name' => $member->user->name]));
        }

        $previous = $member->role;
        $member->update(['role' => $data['role']]);
        if ($member->role !== $previous) {
            $member->user->notify(HubNotice::roleChanged($company, $member->role));
        }

        return $this->done($company, __('Role updated.'));
    }

    public function destroy(Request $request, Company $company, CompanyMember $member): RedirectResponse
    {
        $role = $this->authorizeRole($request, $company, CompanyRole::Manager);
        abort_unless($member->company_id === $company->id, 404);

        $allowed = $member->user_id !== $request->user()->id
            && $member->role !== CompanyRole::Owner
            && ($role === CompanyRole::Owner || $member->role === CompanyRole::Driver);

        if (! $allowed) {
            return $this->fail($company, __('You can’t remove that member.'));
        }

        $member->delete();
        $member->user->notify(HubNotice::removedFromCompany($company));

        return $this->done($company, __(':name was removed from the company.', ['name' => $member->user->name]));
    }

    /**
     * The members at `$minimum` or above (Manager = owner and managers), as users.
     *
     * @return Collection<int, User>
     */
    private function staffOf(Company $company, CompanyRole $minimum): Collection
    {
        $roles = $minimum === CompanyRole::Owner
            ? [CompanyRole::Owner]
            : [CompanyRole::Owner, CompanyRole::Manager];

        return User::query()
            ->whereIn('id', $company->members()->whereIn('role', $roles)->select('user_id'))
            ->get();
    }

    /** Adds `$user` as a driver; false when they are already in a company. */
    private function addMember(Company $company, User $user): bool
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

    /** Owner ⊃ manager: `$minimum` Manager lets owners through too. */
    private function authorizeRole(Request $request, Company $company, CompanyRole $minimum): CompanyRole
    {
        $role = $company->roleOf($request->user());
        $ok = match ($minimum) {
            CompanyRole::Owner => $role === CompanyRole::Owner,
            CompanyRole::Manager => $role?->canManage() ?? false,
            CompanyRole::Driver => $role !== null,
        };
        abort_unless($ok, 403);

        /** @var CompanyRole $role */
        return $role;
    }

    private function done(Company $company, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('companies.show', $company);
    }

    private function fail(Company $company, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('companies.show', $company);
    }
}
