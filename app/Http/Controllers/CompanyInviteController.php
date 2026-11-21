<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\User;
use App\Support\CompanyRoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company invite links (#5221). Owners and managers make links with an expiry and a use
 * limit, and revoke them. Anyone with a live link can join, whatever the company's
 * recruitment setting: the link is the company's own "yes".
 */
class CompanyInviteController extends Controller
{
    public function store(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeManage($request, $company);

        $data = $request->validate([
            'expires_days' => ['required', 'integer', Rule::in(CompanyInvite::EXPIRY_DAYS)],
            'max_uses' => ['required', 'integer', Rule::in(CompanyInvite::USE_LIMITS)],
        ]);

        if ($company->invites()->usable()->count() >= CompanyInvite::MAX_ACTIVE) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('A company can have :n live invite links. Revoke one first.', ['n' => CompanyInvite::MAX_ACTIVE])]);

            return $this->toEdit($company);
        }

        /** @var User $user */
        $user = $request->user();
        [$invite, $token] = CompanyInvite::issue($company, $user, (int) $data['expires_days'], (int) $data['max_uses']);

        // The only time the link exists in full: the database keeps its hash.
        Inertia::flash('invite', ['id' => $invite->id, 'url' => route('invites.show', $token)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invite link created. Copy it now: it is only shown once.')]);

        return $this->toEdit($company);
    }

    public function destroy(Request $request, Company $company, CompanyInvite $invite): RedirectResponse
    {
        $this->authorizeManage($request, $company);
        abort_unless($invite->company_id === $company->id, 404);

        if ($invite->revoked_at === null) {
            $invite->forceFill(['revoked_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invite link revoked.')]);

        return $this->toEdit($company);
    }

    /** The page a link opens: who it's for, and a Join button when it can be used. */
    public function show(Request $request, string $token): Response
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $invite = CompanyInvite::findByToken($token);
        $company = $invite?->company;

        if ($viewer === null && $invite !== null) {
            // Sign-in and registration come back here (Fortify redirects to the intended URL).
            $request->session()->put('url.intended', $request->url());
        }

        $viewerCompany = $viewer?->membership?->company;

        return Inertia::render('invites/Show', [
            'token' => $token,
            'company' => $company === null ? null : [
                ...$company->summary(),
                'description' => $company->description,
                'members' => $company->members()->count(),
            ],
            'problem' => $invite === null
                ? __('This invite link doesn’t exist. Ask the company for a new one.')
                : $invite->problem(),
            'viewer' => [
                'signedIn' => $viewer !== null,
                'memberHere' => $company !== null && $viewerCompany?->is($company) === true,
                'otherCompany' => $viewerCompany !== null && ! $viewerCompany->is($company)
                    ? $viewerCompany->summary()
                    : null,
            ],
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invite = CompanyInvite::findByToken($token);

        if ($invite === null) {
            return $this->fail($token, __('This invite link doesn’t exist. Ask the company for a new one.'));
        }

        $company = $invite->company;
        if ($company->roleOf($user) !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('You’re already in :name.', ['name' => $company->name])]);

            return to_route('companies.show', $company);
        }
        if ($user->membership !== null) {
            return $this->fail($token, __('Leave your current company first.'));
        }

        $problem = $invite->problem();
        if ($problem !== null) {
            return $this->fail($token, $problem);
        }
        if (! $invite->claim()) {
            // Revoked or used up between the page load and the click.
            return $this->fail($token, $invite->refresh()->problem() ?? __('This invite link can’t be used any more.'));
        }
        if (! CompanyRoster::add($company, $user)) {
            $invite->release();

            return $this->fail($token, __('Leave your current company first.'));
        }

        CompanyRoster::announceJoin($company, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome to :name!', ['name' => $company->name])]);

        return to_route('companies.show', $company);
    }

    private function toEdit(Company $company): RedirectResponse
    {
        // No `#invites` fragment: Inertia answers a redirect with one as a 409 + X-Inertia-Redirect,
        // which the browser logs as an error. The forms keep their scroll position instead.
        return to_route('companies.edit', $company);
    }

    private function fail(string $token, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('invites.show', $token);
    }

    private function authorizeManage(Request $request, Company $company): void
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);
    }
}
