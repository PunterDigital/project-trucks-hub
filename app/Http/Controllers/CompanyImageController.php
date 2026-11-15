<?php

namespace App\Http\Controllers;

use App\Enums\ImageKind;
use App\Models\Company;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Company logo and banner (#4644): owners and managers, from the edit page. */
class CompanyImageController extends Controller
{
    public function store(Request $request, Company $company, string $kind): RedirectResponse
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);

        $request->validate(['image' => ImageStore::RULES]);

        $kind = self::kind($kind);
        $column = self::column($kind);
        $company->forceFill([
            $column => ImageStore::replace($request->file('image'), $kind, $company->getAttribute($column)),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $kind === ImageKind::Logo ? __('Logo updated.') : __('Banner updated.')]);

        return to_route('companies.edit', $company);
    }

    public function destroy(Request $request, Company $company, string $kind): RedirectResponse
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);

        $kind = self::kind($kind);
        $column = self::column($kind);
        ImageStore::delete($company->getAttribute($column));
        $company->forceFill([$column => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $kind === ImageKind::Logo ? __('Logo removed.') : __('Banner removed.')]);

        return to_route('companies.edit', $company);
    }

    private static function kind(string $kind): ImageKind
    {
        return $kind === 'banner' ? ImageKind::Banner : ImageKind::Logo;
    }

    private static function column(ImageKind $kind): string
    {
        return $kind === ImageKind::Banner ? 'banner_path' : 'logo_path';
    }
}
