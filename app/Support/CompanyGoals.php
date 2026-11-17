<?php

namespace App\Support;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyGoal;
use App\Models\CompanyMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Company goals (#4649): monthly targets for distance, deliveries and income, measured against
 * the company's counted jobs ({@see Stats}) in that UTC month. Past months are shown as hit or
 * missed; the current month also lists what each member has contributed.
 */
final class CompanyGoals
{
    /** Past months shown on the company page. */
    public const HISTORY_MONTHS = 12;

    public static function monthKey(?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now('UTC'))->format('Y-m');
    }

    public static function monthStart(string $key): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $key, 'UTC') ?: CarbonImmutable::now('UTC')->startOfMonth();
    }

    /**
     * Replace the current month's targets: a metric left null (or missing) has no goal.
     *
     * @param  array<string, int|null>  $targets  metric => target
     */
    public static function setTargets(Company $company, array $targets): void
    {
        $month = self::monthKey();

        DB::transaction(function () use ($company, $targets, $month) {
            foreach (CompanyGoal::METRICS as $metric) {
                $target = $targets[$metric] ?? null;

                if ($target === null) {
                    $company->goals()->where('month', $month)->where('metric', $metric)->delete();

                    continue;
                }

                $company->goals()->updateOrCreate(['month' => $month, 'metric' => $metric], ['target' => $target]);
            }
        });
    }

    /**
     * What the company page shows.
     *
     * @return array{
     *     month: string,
     *     current: list<array{metric: string, target: int, progress: float|int, percent: float, contributors: list<array{driver: array{name: string, username: string|null, avatar: string|null}, value: float|int, percent: float}>}>,
     *     history: list<array{month: string, goals: list<array{metric: string, target: int, achieved: float|int, hit: bool}>}>
     * }
     */
    public static function forPage(Company $company, ?User $viewer, ?CompanyRole $role): array
    {
        $month = self::monthKey();
        $start = self::monthStart($month);
        $deliveries = $company->deliveries()->getQuery();

        $current = $company->goals()->where('month', $month)->get()->keyBy('metric');
        $currentGoals = [];

        if ($current->isNotEmpty()) {
            $totals = Stats::totalsBetween($deliveries, $start, $start->addMonth());
            $contributions = self::contributions($company, $viewer, $role, $start, $totals);

            foreach (CompanyGoal::METRICS as $metric) {
                /** @var CompanyGoal|null $goal */
                $goal = $current->get($metric);

                if ($goal === null) {
                    continue;
                }

                $contributors = [];

                foreach ($contributions as $c) {
                    if ($c[$metric] > 0) {
                        $contributors[] = [
                            'driver' => $c['driver'],
                            'value' => $c[$metric],
                            'percent' => self::percent($c[$metric], $goal->target),
                        ];
                    }
                }

                usort($contributors, fn (array $a, array $b) => $b['value'] <=> $a['value']);

                $currentGoals[] = [
                    'metric' => $metric,
                    'target' => $goal->target,
                    'progress' => $totals[$metric],
                    'percent' => self::percent($totals[$metric], $goal->target),
                    'contributors' => $contributors,
                ];
            }
        }

        $past = $company->goals()
            ->where('month', '<', $month)
            ->orderByDesc('month')
            ->get()
            ->groupBy('month')
            ->take(self::HISTORY_MONTHS);

        $history = [];

        foreach ($past as $key => $goals) {
            $from = self::monthStart((string) $key);
            $totals = Stats::totalsBetween($deliveries, $from, $from->addMonth());
            $byMetric = $goals->keyBy('metric');
            $rows = [];

            foreach (CompanyGoal::METRICS as $metric) {
                /** @var CompanyGoal|null $goal */
                $goal = $byMetric->get($metric);

                if ($goal !== null) {
                    $rows[] = [
                        'metric' => $metric,
                        'target' => $goal->target,
                        'achieved' => $totals[$metric],
                        'hit' => $totals[$metric] >= $goal->target,
                    ];
                }
            }

            $history[] = ['month' => (string) $key, 'goals' => $rows];
        }

        return ['month' => $month, 'current' => $currentGoals, 'history' => $history];
    }

    /**
     * Each current member's counted totals this month, plus one "Former members" row for jobs
     * by drivers who have since left (they still count for the company). Private drivers are
     * "Private driver" to anyone who couldn't see them on the members list.
     *
     * @param  array{distance_km: float, deliveries: int, income: int}  $totals
     * @return list<array{driver: array{name: string, username: string|null, avatar: string|null}, distance_km: float, deliveries: int, income: int}>
     */
    private static function contributions(Company $company, ?User $viewer, ?CompanyRole $role, CarbonImmutable $start, array $totals): array
    {
        $byUser = $company->deliveries()->counted()->toBase()
            ->where('deliveries.completed_at', '>=', $start)
            ->where('deliveries.completed_at', '<', $start->addMonth())
            ->selectRaw('deliveries.user_id, COUNT(*) as deliveries, COALESCE(SUM(deliveries.distance_km), 0) as distance_km, COALESCE(SUM(deliveries.income), 0) as income')
            ->groupBy('deliveries.user_id')
            ->get()
            ->keyBy('user_id');

        $rows = [];
        $sumKm = 0.0;
        $sumDeliveries = 0;
        $sumIncome = 0;

        $members = $company->members()->with('user')->get()
            ->reject(fn (CompanyMember $m) => $m->user->isSuspended());

        foreach ($members as $member) {
            $stats = $byUser->get($member->user_id);

            if ($stats === null) {
                continue;
            }

            $user = $member->user;
            $visible = $role !== null || ! $user->profile_private || $viewer?->is($user);
            $row = [
                'driver' => $visible
                    ? ['name' => $user->name, 'username' => $user->username, 'avatar' => $user->avatar()]
                    : ['name' => __('Private driver'), 'username' => null, 'avatar' => null],
                'distance_km' => round((float) $stats->distance_km, 1),
                'deliveries' => (int) $stats->deliveries,
                'income' => (int) $stats->income,
            ];

            $sumKm += $row['distance_km'];
            $sumDeliveries += $row['deliveries'];
            $sumIncome += $row['income'];
            $rows[] = $row;
        }

        $former = [
            'driver' => ['name' => __('Former members'), 'username' => null, 'avatar' => null],
            'distance_km' => round(max(0.0, $totals['distance_km'] - $sumKm), 1),
            'deliveries' => max(0, $totals['deliveries'] - $sumDeliveries),
            'income' => max(0, $totals['income'] - $sumIncome),
        ];

        if ($former['distance_km'] > 0 || $former['deliveries'] > 0 || $former['income'] > 0) {
            $rows[] = $former;
        }

        return $rows;
    }

    private static function percent(float|int $value, int $target): float
    {
        return $target > 0 ? round($value / $target * 100, 1) : 0.0;
    }
}
