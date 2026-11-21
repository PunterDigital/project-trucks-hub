<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\CompanyRank;
use App\Models\Delivery;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Company ranks (#5223): up to `MAX` ranks a company defines, each reached at a number of
 * kilometres driven **for this company** (the member's earned jobs synced with this
 * company's id: counted, plus a suspended driver's, so a suspension hides nothing it would
 * have to give back).
 *
 * A member's rank is the highest one their distance reaches, or the rank an owner or manager
 * pinned if that one is higher: automatic promotion never lowers a pin. It is stored on
 * `company_members.rank_id` and worked out again after every job sync (`refreshUser`, with a
 * bell notice and a Discord post on promotion), whenever `Progress::refresh` runs (job deleted
 * or hidden, staff review, joining: silent), when the ranks change (`save`: silent, so setting
 * up ranks doesn't ring everyone's bell) and on a pin (`pin`: notifies like a sync).
 *
 * @phpstan-type RankSummary array{id: int, name: string, color: string, min_km: int}
 */
class CompanyRanks
{
    public const MAX = 12;

    public const NAME_MAX = 40;

    /** 10 million km: far beyond any real driver, small enough for an unsigned int. */
    public const MAX_KM = 10_000_000;

    /** The colours the edit page offers first; any `#rrggbb` is accepted. */
    public const DEFAULT_COLOR = '#64748b';

    /**
     * @return list<RankSummary>
     */
    public static function for(Company $company): array
    {
        return array_values($company->ranks()->get()->map(fn (CompanyRank $r) => $r->summary())->all());
    }

    /**
     * Validates the edit page's list and stores it, keeping the ids (and so the pins) of ranks
     * that stay. An empty list removes every rank. Members are then re-ranked silently.
     *
     * @param  array<string, mixed>  $input
     */
    public static function save(Company $company, array $input): void
    {
        $data = validator($input, [
            'ranks' => ['present', 'array', 'max:'.self::MAX],
            'ranks.*' => ['array'],
            'ranks.*.id' => ['nullable', 'integer'],
            'ranks.*.name' => ['required', 'string', 'max:'.self::NAME_MAX, 'distinct:ignore_case'],
            'ranks.*.color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'ranks.*.min_km' => ['required', 'integer', 'min:0', 'max:'.self::MAX_KM, 'distinct'],
        ], [
            'ranks.*.name.distinct' => __('Two ranks have the same name.'),
            'ranks.*.min_km.distinct' => __('Two ranks start at the same distance.'),
            'ranks.*.color.regex' => __('Pick a colour.'),
        ], [
            'ranks.*.name' => __('rank name'),
            'ranks.*.min_km' => __('distance'),
            'ranks.*.color' => __('colour'),
        ])->validate();

        /** @var list<array{id?: int|null, name: string, color: string, min_km: int|string}> $rows */
        $rows = array_values($data['ranks']);

        DB::transaction(function () use ($company, $rows) {
            $existing = $company->ranks()->get()->keyBy('id');
            $keep = [];

            foreach ($rows as $row) {
                $attributes = [
                    'name' => trim($row['name']),
                    'color' => strtolower($row['color']),
                    'min_km' => (int) $row['min_km'],
                ];
                $rank = isset($row['id']) ? $existing->get((int) $row['id']) : null;

                if ($rank !== null) {
                    $rank->update($attributes);
                } else {
                    $rank = $company->ranks()->create($attributes);
                }
                $keep[] = $rank->id;
            }

            $gone = $existing->keys()->diff($keep)->values()->all();
            if ($gone !== []) {
                // The foreign keys null these too; doing it here doesn't depend on the driver
                // enforcing them.
                CompanyMember::query()->whereIn('rank_id', $gone)->update(['rank_id' => null]);
                CompanyMember::query()->whereIn('pinned_rank_id', $gone)->update(['pinned_rank_id' => null]);
                CompanyRank::query()->whereIn('id', $gone)->delete();
            }
        });

        self::refreshCompany($company);
    }

    /** Re-ranks every member of the company without telling anyone. */
    public static function refreshCompany(Company $company): void
    {
        $ranks = $company->ranks()->get();

        $km = Delivery::query()->earned()->toBase()
            ->where('company_id', $company->id)
            ->selectRaw('user_id, SUM(distance_km) as km')
            ->groupBy('user_id')
            ->pluck('km', 'user_id');

        foreach ($company->members()->get() as $member) {
            self::apply($member, $ranks, (float) ($km[$member->user_id] ?? 0), notify: false);
        }
    }

    /**
     * Re-ranks the driver in their current company, if they have one. `$notify` rings the bell
     * and posts to the company's Discord when this promotes them.
     */
    public static function refreshUser(User $user, bool $notify): void
    {
        $member = CompanyMember::query()->where('user_id', $user->id)->first();
        if ($member === null) {
            return;
        }

        $ranks = CompanyRank::query()->where('company_id', $member->company_id)->orderBy('min_km')->orderBy('id')->get();
        if ($ranks->isEmpty() && $member->rank_id === null) {
            return;
        }

        self::apply($member, $ranks, self::distance($member), $notify);
    }

    /**
     * Pins `$rank` (or clears the pin with null) and re-ranks the member, telling them when it
     * promotes them.
     */
    public static function pin(CompanyMember $member, ?CompanyRank $rank): void
    {
        $member->forceFill(['pinned_rank_id' => $rank?->id])->save();

        $ranks = CompanyRank::query()->where('company_id', $member->company_id)->orderBy('min_km')->orderBy('id')->get();
        self::apply($member, $ranks, self::distance($member), notify: true);
    }

    /** Counted kilometres the member drove for their company. */
    public static function distance(CompanyMember $member): float
    {
        return (float) Delivery::query()->earned()
            ->where('company_id', $member->company_id)
            ->where('user_id', $member->user_id)
            ->sum('distance_km');
    }

    /**
     * The rank `$km` reaches, or the pinned one when that is higher.
     *
     * @param  Collection<int, CompanyRank>  $ranks  lowest first
     */
    public static function rankFor(Collection $ranks, float $km, ?int $pinnedId): ?CompanyRank
    {
        $earned = $ranks->filter(fn (CompanyRank $r) => $km >= $r->min_km)->last();
        $pinned = $pinnedId !== null ? $ranks->firstWhere('id', $pinnedId) : null;

        if ($pinned !== null && ($earned === null || $pinned->min_km > $earned->min_km)) {
            return $pinned;
        }

        return $earned;
    }

    /** @param  Collection<int, CompanyRank>  $ranks */
    private static function apply(CompanyMember $member, Collection $ranks, float $km, bool $notify): void
    {
        $rank = self::rankFor($ranks, $km, $member->pinned_rank_id);
        if ($rank?->id === $member->rank_id) {
            return;
        }

        $old = $member->rank_id !== null ? $ranks->firstWhere('id', $member->rank_id) : null;
        $member->forceFill(['rank_id' => $rank?->id])->save();

        $promoted = $rank !== null && ($old === null || $rank->min_km > $old->min_km);
        if (! $notify || ! $promoted) {
            return;
        }

        $company = $member->company;
        $user = $member->user;
        $user->notify(HubNotice::rankPromoted($company, $rank->name));
        DiscordWebhook::rankPromoted($company, $user, $rank);
    }
}
