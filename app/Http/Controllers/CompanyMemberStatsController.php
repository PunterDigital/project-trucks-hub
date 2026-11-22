<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyMemberStats;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Member stats CSV (#5226), for the company's owners and managers. */
class CompanyMemberStatsController extends Controller
{
    public function __invoke(Request $request, Company $company): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($company->roleOf($user)?->canManage() ?? false, 403);

        $period = $request->query('period', CompanyMemberStats::ALL);
        abort_unless(CompanyMemberStats::isPeriod($period), 404);
        /** @var string $period */

        return response(CompanyMemberStats::csv($company, $period), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.CompanyMemberStats::filename($company, $period).'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
