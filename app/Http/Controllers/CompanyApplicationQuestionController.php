<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\ApplicationQuestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Application questions (#5222): owners and managers set what the Apply form asks. */
class CompanyApplicationQuestionController extends Controller
{
    public function update(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);

        ApplicationQuestions::save($company, ['questions' => $request->input('questions', [])]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application questions saved.')]);

        // No fragment: an Inertia redirect with one comes back as a 409 (Hub #5221). The form
        // keeps its scroll position instead.
        return to_route('companies.edit', $company);
    }
}
