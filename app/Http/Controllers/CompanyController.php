<?php

namespace App\Http\Controllers;

use App\Achievements\Progress;
use App\Enums\ApplicationStatus;
use App\Enums\CompanyRole;
use App\Enums\Recruitment;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\CompanyMember;
use App\Models\Delivery;
use App\Models\User;
use App\Support\Feed;
use App\Support\ImageStore;
use App\Support\Period;
use App\Support\Stats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $companies = Company::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('tag', 'like', '%'.$search.'%')))
            ->withCount('members')
            ->withSum(['deliveries as distance_km' => Delivery::countedConstraint()], 'distance_km')
            ->orderByDesc('members_count')
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Company $c) => [
                ...$c->summary(),
                'description' => Str::limit((string) $c->description, 140),
                'recruitment' => $c->recruitment->value,
                'members' => (int) $c->getAttribute('members_count'),
                'distance_km' => round((float) $c->getAttribute('distance_km'), 1),
            ]);

        return Inertia::render('companies/Index', [
            'companies' => $companies,
            'search' => $search,
            'canCreate' => $request->user() !== null && $request->user()->membership === null,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()->membership !== null) {
            return $this->alreadyInCompany();
        }

        return Inertia::render('companies/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->membership !== null) {
            return $this->alreadyInCompany();
        }

        $request->merge(['tag' => Str::upper(trim((string) $request->input('tag')))]);
        $data = $request->validate($this->rules());

        $company = DB::transaction(function () use ($data, $user) {
            $company = Company::query()->create([
                ...$data,
                'slug' => $this->uniqueSlug($data['name']),
            ]);
            $company->members()->create(['user_id' => $user->id, 'role' => CompanyRole::Owner]);
            $this->withdrawApplications($user);

            return $company;
        });

        // "Team player" (#4645).
        Progress::refresh($user->unsetRelation('membership'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company created. You are its owner.')]);

        return to_route('companies.show', $company);
    }

    public function show(Request $request, Company $company): Response
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $role = $company->roleOf($viewer);
        $deliveries = $company->deliveries()->getQuery();
        $month = Period::Month->start();

        $memberStats = $company->deliveries()->counted()->toBase()
            ->where('completed_at', '>=', $month)
            ->selectRaw('user_id, COUNT(*) as deliveries, SUM(distance_km) as distance_km')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $members = $company->members()
            ->with('user.liveStatus')
            ->get()
            // Suspended drivers (#4651) are off every public page, their company's too.
            ->reject(fn (CompanyMember $m) => $m->user->isSuspended())
            ->sortBy(fn (CompanyMember $m) => [array_search($m->role, CompanyRole::cases(), true), $m->user->name])
            ->values()
            ->map(function (CompanyMember $m) use ($memberStats, $viewer, $role) {
                $visible = $role !== null || ! $m->user->profile_private || $viewer?->is($m->user);
                $stats = $memberStats->get($m->user_id);

                return [
                    'id' => $m->id,
                    'role' => $m->role->value,
                    'joined_at' => $m->created_at?->toIso8601String(),
                    'driver' => $visible
                        ? ['name' => $m->user->name, 'username' => $m->user->username, 'avatar' => $m->user->avatar()]
                        : ['name' => __('Private driver'), 'username' => null, 'avatar' => null],
                    'month' => [
                        'deliveries' => (int) ($stats->deliveries ?? 0),
                        'distance_km' => round((float) ($stats->distance_km ?? 0), 1),
                    ],
                    'on_the_road' => $visible && $m->user->liveStatus?->isFresh(),
                ];
            });

        $application = $viewer
            ? $company->applications()->where('user_id', $viewer->id)->where('status', ApplicationStatus::Pending)->first()
            : null;

        return Inertia::render('companies/Show', [
            'company' => [
                ...$company->summary(),
                'description' => $company->description,
                'recruitment' => $company->recruitment->value,
                'banner' => ImageStore::url($company->banner_path),
                'created_at' => $company->created_at?->toIso8601String(),
            ],
            'role' => $role?->value,
            'viewer' => [
                'signedIn' => $viewer !== null,
                'inOtherCompany' => $viewer?->membership !== null && $role === null,
                'pendingApplication' => $application?->id,
            ],
            'stats' => [
                'week' => Stats::totals($deliveries, Period::Week),
                'month' => Stats::totals($deliveries, Period::Month),
                'all' => Stats::totals($deliveries, Period::All),
            ],
            'members' => $members,
            'onTheRoad' => Feed::onTheRoad($viewer, 20, fn ($q) => $q->whereHas('user.membership', fn (Builder $m) => $m->where('company_id', $company->id))),
            'latest' => Feed::latestDeliveries($viewer, 15, fn ($q) => $q->where('deliveries.company_id', $company->id)),
            'applications' => $role?->canManage()
                ? $company->pendingApplications()->with('user')->oldest()->get()->map(fn (CompanyApplication $a) => [
                    'id' => $a->id,
                    'message' => $a->message,
                    'created_at' => $a->created_at?->toIso8601String(),
                    'driver' => ['name' => $a->user->name, 'username' => $a->user->username, 'avatar' => $a->user->avatar()],
                ])
                : [],
        ]);
    }

    public function edit(Request $request, Company $company): Response
    {
        $this->authorizeManage($request, $company);

        return Inertia::render('companies/Edit', [
            'company' => [
                ...$company->summary(),
                'description' => $company->description,
                'recruitment' => $company->recruitment->value,
                'banner' => ImageStore::url($company->banner_path),
            ],
            'isOwner' => $company->roleOf($request->user()) === CompanyRole::Owner,
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeManage($request, $company);

        $request->merge(['tag' => Str::upper(trim((string) $request->input('tag')))]);
        $data = $request->validate($this->rules($company));

        if ($data['name'] !== $company->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $company->id);
        }
        $company->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company updated.')]);

        return to_route('companies.show', $company);
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        abort_unless($company->roleOf($request->user()) === CompanyRole::Owner, 403);

        $request->validate(['confirm_name' => ['required', Rule::in([$company->name])]], [
            'confirm_name.in' => __('Type the company name exactly to delete it.'),
        ]);

        // Members' jobs stay on their profiles; they just lose the company link.
        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company deleted.')]);

        return to_route('companies.index');
    }

    /** @return array<string, mixed> */
    private function rules(?Company $company = null): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:60', Rule::unique('companies', 'name')->ignore($company?->id)],
            'tag' => ['required', 'string', 'min:2', 'max:5', 'regex:/^[A-Z0-9]+$/', Rule::unique('companies', 'tag')->ignore($company?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'recruitment' => ['required', Rule::enum(Recruitment::class)],
        ];
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $n = 1;
        while (Company::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $n++;
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private function authorizeManage(Request $request, Company $company): void
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);
    }

    private function alreadyInCompany(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Leave your current company first.')]);

        return to_route('companies.index');
    }

    private function withdrawApplications(User $user): void
    {
        CompanyApplication::query()
            ->where('user_id', $user->id)
            ->where('status', ApplicationStatus::Pending)
            ->update(['status' => ApplicationStatus::Withdrawn]);
    }
}
