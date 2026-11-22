<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\CompanyActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Monthly activity requirement (#5225): owners and managers set the minimums. */
class CompanyActivityController extends Controller
{
    public function update(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);

        CompanyActivity::save($company, $request->only(['min_km', 'min_deliveries']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Monthly minimum saved.')]);

        // No fragment: an Inertia redirect with one comes back as a 409 (Hub #5221).
        return to_route('companies.edit', $company);
    }
}
