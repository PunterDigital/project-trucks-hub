<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyGoal;
use App\Support\CompanyGoals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Company goals (#4649): owners and managers set this month's targets. */
class CompanyGoalController extends Controller
{
    public function update(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);

        $rules = [];

        foreach (CompanyGoal::METRICS as $metric) {
            $rules[$metric] = ['nullable', 'integer', 'min:1', 'max:'.CompanyGoal::MAX_TARGET];
        }

        $data = $request->validate($rules, [], [
            'distance_km' => __('distance'),
            'deliveries' => __('deliveries'),
            'income' => __('income'),
        ]);

        CompanyGoals::setTargets($company, array_map(
            fn ($value) => $value === null ? null : (int) $value,
            $data,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goals saved for this month.')]);

        return to_route('companies.show', $company);
    }
}
