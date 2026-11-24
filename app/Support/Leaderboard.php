<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use stdClass;

/**
 * Rankings over counted jobs ({@see Delivery::scopeCounted()}). Drivers with a private profile
 * stay off the driver board; their jobs still count towards their company's total.
 */
final class Leaderboard
{
    public const METRICS = ['distance', 'deliveries', 'income'];

    /**
     * Boards are the same for every viewer, so each one is worked out at most once a minute
     * site-wide (#5734) instead of on every view and auto-refresh. Up to a minute stale.
     */
    public const CACHE_SECONDS = 60;

    /** @return list<array<string, mixed>> */
    public static function drivers(Period $period, string $metric, int $limit = 50): array
    {
        /** @var list<array<string, mixed>> */
        return Cache::remember(
            "leaderboard.drivers.{$period->value}.{$metric}.{$limit}",
            self::CACHE_SECONDS,
            fn () => self::computeDrivers($period, $metric, $limit),
        );
    }

    /** @return list<array<string, mixed>> */
    public static function companies(Period $period, string $metric, int $limit = 50): array
    {
        /** @var list<array<string, mixed>> */
        return Cache::remember(
            "leaderboard.companies.{$period->value}.{$metric}.{$limit}",
            self::CACHE_SECONDS,
            fn () => self::computeCompanies($period, $metric, $limit),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function computeDrivers(Period $period, string $metric, int $limit): array
    {
        $rows = self::base($period, $metric)
            ->join('users', 'users.id', '=', 'deliveries.user_id')
            ->where('users.profile_private', false)
            ->whereNull('users.suspended_at')
            ->groupBy('deliveries.user_id')
            ->selectRaw('deliveries.user_id as subject_id')
            ->limit($limit)
            ->get();

        $users = User::query()
            ->with('membership.company')
            ->whereIn('id', $rows->pluck('subject_id'))
            ->get()
            ->keyBy('id');

        return self::rank($rows, fn (int $id) => $users->get($id)?->publicSummary());
    }

    /** @return list<array<string, mixed>> */
    private static function computeCompanies(Period $period, string $metric, int $limit): array
    {
        $rows = self::base($period, $metric)
            ->whereNotNull('deliveries.company_id')
            ->groupBy('deliveries.company_id')
            ->selectRaw('deliveries.company_id as subject_id')
            ->limit($limit)
            ->get();

        $companies = Company::query()
            ->withCount('members')
            ->whereIn('id', $rows->pluck('subject_id'))
            ->get()
            ->keyBy('id');

        return self::rank($rows, function (int $id) use ($companies) {
            $company = $companies->get($id);

            return $company ? [...$company->summary(), 'members' => $company->members_count] : null;
        });
    }

    private static function base(Period $period, string $metric): Builder
    {
        $metric = in_array($metric, self::METRICS, true) ? $metric : 'distance';
        $query = Delivery::query()->counted();

        if ($start = $period->start()) {
            $query->where('deliveries.completed_at', '>=', $start);
        }

        return $query->toBase()
            ->selectRaw(Stats::metricExpression($metric).' as score')
            ->selectRaw('COUNT(*) as deliveries')
            ->selectRaw('SUM(deliveries.distance_km) as distance_km')
            ->selectRaw('SUM('.Delivery::INCOME_SQL.') as income')
            ->orderByDesc('score');
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  callable(int): (array<string, mixed>|null)  $subject
     * @return list<array<string, mixed>>
     */
    private static function rank(Collection $rows, callable $subject): array
    {
        $out = [];
        foreach ($rows as $row) {
            $who = $subject((int) $row->subject_id);
            if ($who === null) {
                continue;
            }
            $out[] = [
                'rank' => count($out) + 1,
                'subject' => $who,
                'deliveries' => (int) $row->deliveries,
                'distance_km' => round((float) $row->distance_km, 1),
                'income' => (int) $row->income,
            ];
        }

        return $out;
    }
}
