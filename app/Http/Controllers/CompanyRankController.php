<?php

namespace App\Http\Controllers;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\CompanyRank;
use App\Support\CompanyRanks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Company ranks (#5223): owners and managers set the ranks and pin a member's rank. */
class CompanyRankController extends Controller
{
    public function update(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);

        CompanyRanks::save($company, ['ranks' => $request->input('ranks', [])]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ranks saved.')]);

        // No fragment: an Inertia redirect with one comes back as a 409 (Hub #5221).
        return to_route('companies.edit', $company);
    }

    /**
     * Pins a rank on a member (null clears it). The owner can pin anyone; a manager pins
     * drivers and themselves, the same reach as the member list's other controls.
     */
    public function pin(Request $request, Company $company, CompanyMember $member): RedirectResponse
    {
        abort_unless($member->company_id === $company->id, 404);

        $viewer = $request->user();
        $role = $company->roleOf($viewer);
        $allowed = $role === CompanyRole::Owner
            || ($role === CompanyRole::Manager && ($member->role === CompanyRole::Driver || $member->user_id === $viewer->id));
        abort_unless($allowed, 403);

        $data = $request->validate(['rank_id' => ['nullable', 'integer']]);
        $rank = null;
        if (($data['rank_id'] ?? null) !== null) {
            $rank = CompanyRank::query()->where('company_id', $company->id)->whereKey((int) $data['rank_id'])->first();
            abort_if($rank === null, 422, __('That rank no longer exists.'));
        }

        CompanyRanks::pin($member, $rank);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $rank !== null
                ? __(':name is pinned at :rank or above.', ['name' => $member->user->name, 'rank' => $rank->name])
                : __('Pin removed: :name’s rank follows their distance.', ['name' => $member->user->name]),
        ]);

        return back();
    }
}
