<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly activity requirement (#5225): a company can ask every member for a minimum distance
 * and/or number of counted jobs per UTC month. A member meets it by reaching every minimum
 * that is set, with the company's counted jobs ({@see Delivery::scopeCounted()}, synced while
 * in the company) completed in that month **after they joined**. Someone who joined mid-month
 * owes only their share of it: the minimum times the days left in the month from their join
 * day (that day included) over the month's days, rounded up.
 *
 * Nothing is stored per month: last month is judged against the current minimum. Nobody is
 * ever removed for missing it; on the 1st `hub:activity-check` tells the owner and managers
 * who did.
 *
 * @phpstan-type Progress array{distance_km: float, deliveries: int, required_km: int|null, required_deliveries: int|null, met: bool|null}
 * @phpstan-type MemberActivity array{last_job_at: string|null, month: Progress, last_month: Progress|null}
 */
final class CompanyActivity
{
    /** 100,000 km in a month: far beyond any real driver. */
    public const MAX_KM = 100_000;

    public const MAX_DELIVERIES = 1_000;

    public static function hasRequirement(Company $company): bool
    {
        return $company->activity_min_km !== null || $company->activity_min_deliveries !== null;
    }

    /** @return array{min_km: int|null, min_deliveries: int|null} */
    public static function requirement(Company $company): array
    {
        return ['min_km' => $company->activity_min_km, 'min_deliveries' => $company->activity_min_deliveries];
    }

    /**
     * Validates and stores the edit page's minimums; a blank box means no minimum for it.
     *
     * @param  array<string, mixed>  $input
     */
    public static function save(Company $company, array $input): void
    {
        $data = validator($input, [
            'min_km' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_KM],
            'min_deliveries' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DELIVERIES],
        ], [], [
            'min_km' => __('minimum distance'),
            'min_deliveries' => __('minimum jobs'),
        ])->validate();

        $company->forceFill([
            'activity_min_km' => isset($data['min_km']) ? (int) $data['min_km'] : null,
            'activity_min_deliveries' => isset($data['min_deliveries']) ? (int) $data['min_deliveries'] : null,
        ])->save();
    }

    /**
     * This month's and last month's progress and the last counted job of each given member,
     * keyed by member id. `met` is null without a requirement; `last_month` is null for a
     * member who joined after it ended.
     *
     * @param  Collection<int, CompanyMember>  $members
     * @return array<int, MemberActivity>
     */
    public static function forMembers(Company $company, Collection $members, ?CarbonImmutable $now = null): array
    {
        if ($members->isEmpty()) {
            return [];
        }

        $now ??= CarbonImmutable::now('UTC');
        $thisMonth = $now->utc()->startOfMonth();
        $lastMonth = $thisMonth->subMonthNoOverflow();
        $userIds = $members->pluck('user_id')->all();

        // Two months of the company's counted jobs by these members, summed per member in PHP
        // because each member's window starts at their own join time.
        $jobs = $company->deliveries()->counted()->toBase()
            ->whereIn('deliveries.user_id', $userIds)
            ->where('deliveries.completed_at', '>=', $lastMonth)
            ->where('deliveries.completed_at', '<', $thisMonth->addMonthNoOverflow())
            ->get(['deliveries.user_id', 'deliveries.completed_at', 'deliveries.distance_km'])
            ->groupBy('user_id');

        $lastJobs = $company->deliveries()->counted()->toBase()
            ->whereIn('deliveries.user_id', $userIds)
            ->selectRaw('deliveries.user_id, MAX(deliveries.completed_at) as last_job_at')
            ->groupBy('deliveries.user_id')
            ->pluck('last_job_at', 'user_id');

        $result = [];

        foreach ($members as $member) {
            $joined = CarbonImmutable::parse($member->created_at ?? $lastMonth)->utc();
            /** @var Collection<int, object{user_id: int, completed_at: string, distance_km: float|string|null}> $mine */
            $mine = $jobs->get($member->user_id, collect());
            $last = $lastJobs->get($member->user_id);

            $result[$member->id] = [
                'last_job_at' => $last !== null ? CarbonImmutable::parse((string) $last, 'UTC')->toIso8601String() : null,
                'month' => self::progress($company, $mine, $thisMonth, $joined),
                'last_month' => $joined < $thisMonth ? self::progress($company, $mine, $lastMonth, $joined) : null,
            ];
        }

        return $result;
    }

    /**
     * Members (suspended ones left out) who missed the month before `$now`'s.
     *
     * @return Collection<int, CompanyMember>
     */
    public static function missedLastMonth(Company $company, ?CarbonImmutable $now = null): Collection
    {
        if (! self::hasRequirement($company)) {
            return collect();
        }

        $members = $company->members()->with('user')->get()
            ->reject(fn (CompanyMember $m) => $m->user->isSuspended())
            ->values();
        $activity = self::forMembers($company, $members, $now);

        return $members
            ->filter(fn (CompanyMember $m) => ($activity[$m->id]['last_month']['met'] ?? null) === false)
            ->sortBy(fn (CompanyMember $m) => $m->user->name)
            ->values();
    }

    /**
     * A member's totals for the month starting at `$start`, from their join time on.
     *
     * @param  Collection<int, object{user_id: int, completed_at: string, distance_km: float|string|null}>  $jobs
     * @return Progress
     */
    private static function progress(Company $company, Collection $jobs, CarbonImmutable $start, CarbonImmutable $joined): array
    {
        $end = $start->addMonthNoOverflow();
        $from = $joined > $start ? $joined : $start;
        $km = 0.0;
        $deliveries = 0;

        foreach ($jobs as $job) {
            $at = CarbonImmutable::parse($job->completed_at, 'UTC');

            if ($at >= $from && $at < $end) {
                $km += (float) $job->distance_km;
                $deliveries++;
            }
        }

        $requiredKm = self::share($company->activity_min_km, $start, $joined);
        $requiredDeliveries = self::share($company->activity_min_deliveries, $start, $joined);
        $met = self::hasRequirement($company)
            ? ($requiredKm === null || $km >= $requiredKm) && ($requiredDeliveries === null || $deliveries >= $requiredDeliveries)
            : null;

        return [
            'distance_km' => round($km, 1),
            'deliveries' => $deliveries,
            'required_km' => $requiredKm,
            'required_deliveries' => $requiredDeliveries,
            'met' => $met,
        ];
    }

    /** The part of a monthly minimum owed by someone who joined during the month. */
    private static function share(?int $minimum, CarbonImmutable $start, CarbonImmutable $joined): ?int
    {
        if ($minimum === null || $joined <= $start) {
            return $minimum;
        }

        $days = $start->daysInMonth;
        $daysLeft = $days - $joined->day + 1;

        return (int) ceil($minimum * $daysLeft / $days);
    }
}
