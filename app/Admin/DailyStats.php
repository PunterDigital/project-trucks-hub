<?php

namespace App\Admin;

use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\Delivery;
use App\Models\DeviceAuthorization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily rollups for the admin dashboard (#4665). Each closed UTC day is worked out once and
 * kept in `daily_stats` (one row per day and metric), so the dashboard reads a few hundred
 * rows however large `deliveries` grows. Today is always live.
 *
 * Closed days are final: every metric is counted by a timestamp inside the day. Link requests
 * are pruned a day after they expire (`DeviceAuthorizationController::start`), which is why
 * that controller fills the last two days before it prunes.
 */
final class DailyStats
{
    public const TABLE = 'daily_stats';

    /** Every stored metric, in the order the dashboard shows them. */
    public const METRICS = [
        'signups_email',
        'signups_steam',
        'jobs_synced',
        'km_synced',
        'active_drivers',
        'companies_created',
        'applications_sent',
        'applications_accepted',
        'links_started',
        'links_approved',
        'links_expired',
    ];

    /**
     * Every metric for one UTC day, counted live. For today, "expired" link requests are
     * only the ones that have expired so far.
     *
     * @return array<string, float>
     */
    public static function forDay(CarbonImmutable $day): array
    {
        $from = $day->utc()->startOfDay();
        $to = $from->addDay();

        /**
         * @template TModel of \Illuminate\Database\Eloquent\Model
         *
         * @param  Builder<TModel>  $query
         * @return Builder<TModel>
         */
        $within = fn (Builder $query, string $column, ?CarbonImmutable $end = null): Builder => $query
            ->where($column, '>=', $from)
            ->where($column, '<', $end ?? $to);

        $jobs = fn (): Builder => $within(Delivery::query(), 'deliveries.created_at');
        $expiredBy = $to->lessThan(CarbonImmutable::now()) ? $to : CarbonImmutable::now();

        return [
            'signups_email' => (float) $within(User::query(), 'created_at')->whereNull('steam_id')->count(),
            'signups_steam' => (float) $within(User::query(), 'created_at')->whereNotNull('steam_id')->count(),
            'jobs_synced' => (float) $jobs()->count(),
            'km_synced' => round((float) $jobs()->sum('distance_km'), 2),
            'active_drivers' => (float) $jobs()->distinct()->count('user_id'),
            'companies_created' => (float) $within(Company::query(), 'created_at')->count(),
            'applications_sent' => (float) $within(CompanyApplication::query(), 'created_at')->count(),
            'applications_accepted' => (float) $within(CompanyApplication::query(), 'decided_at')
                ->where('status', 'accepted')->count(),
            'links_started' => (float) $within(DeviceAuthorization::query(), 'created_at')->count(),
            'links_approved' => (float) $within(DeviceAuthorization::query(), 'approved_at')->count(),
            'links_expired' => (float) $within(DeviceAuthorization::query(), 'expires_at', $expiredBy)
                ->whereNull('approved_at')->whereNull('denied_at')->count(),
        ];
    }

    /**
     * Store every closed day in the range that has no rows yet (or every day, with $force).
     * Today and later are never stored. Days before the first account are stored as zeros
     * without querying. Returns how many days were written.
     */
    public static function fill(?CarbonImmutable $from = null, ?CarbonImmutable $to = null, bool $force = false): int
    {
        $yesterday = CarbonImmutable::now('UTC')->startOfDay()->subDay();
        $firstUser = User::query()->min('created_at');
        $firstDay = $firstUser === null ? null : CarbonImmutable::parse($firstUser, 'UTC')->startOfDay();

        $to = ($to === null || $to->greaterThan($yesterday)) ? $yesterday : $to->utc()->startOfDay();
        $from = $from?->utc()->startOfDay() ?? $firstDay;

        if ($from === null || $from->greaterThan($to)) {
            return 0;
        }

        $stored = $force ? [] : array_flip(
            DB::table(self::TABLE)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('date')
                ->havingRaw('COUNT(*) >= '.count(self::METRICS))
                ->pluck('date')
                ->map(fn ($date): string => substr((string) $date, 0, 10))
                ->all(),
        );

        $written = 0;
        $now = CarbonImmutable::now();

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();

            if (isset($stored[$date])) {
                continue;
            }

            $values = ($firstDay === null || $day->lessThan($firstDay))
                ? array_fill_keys(self::METRICS, 0.0)
                : self::forDay($day);

            DB::table(self::TABLE)->upsert(
                array_map(fn (string $metric): array => [
                    'date' => $date,
                    'metric' => $metric,
                    'value' => $values[$metric],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], self::METRICS),
                ['date', 'metric'],
                ['value', 'updated_at'],
            );
            $written++;
        }

        return $written;
    }

    /**
     * One value per metric per day for the last $days days (today included, live), oldest
     * first. Missing closed days are filled first.
     *
     * @return array{dates: list<string>, values: array<string, list<float>>}
     */
    public static function series(int $days): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $today->subDays($days - 1);

        self::fill($from, $today->subDay());

        $rows = DB::table(self::TABLE)
            ->whereBetween('date', [$from->toDateString(), $today->subDay()->toDateString()])
            ->get(['date', 'metric', 'value']);

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[substr((string) $row->date, 0, 10)][(string) $row->metric] = (float) $row->value;
        }
        $byDay[$today->toDateString()] = self::forDay($today);

        $dates = [];
        $values = array_fill_keys(self::METRICS, []);

        for ($day = $from; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $date = $day->toDateString();
            $dates[] = $date;

            foreach (self::METRICS as $metric) {
                $values[$metric][] = $byDay[$date][$metric] ?? 0.0;
            }
        }

        return ['dates' => $dates, 'values' => $values];
    }

    /**
     * Totals for the last $days days and for the $days before them, plus the daily values
     * of the current window (for sparklines). Active drivers are counted distinct over the
     * whole window, not summed per day.
     *
     * @return array{current: array<string, float>, previous: array<string, float>, daily: array<string, list<float>>}
     */
    public static function periods(int $days): array
    {
        $series = self::series($days * 2);
        $current = [];
        $previous = [];
        $daily = [];

        foreach (self::METRICS as $metric) {
            $all = $series['values'][$metric];
            $daily[$metric] = array_slice($all, $days);
            $previous[$metric] = array_sum(array_slice($all, 0, $days));
            $current[$metric] = array_sum($daily[$metric]);
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $current['active_drivers'] = self::activeDrivers($today->subDays($days - 1), $today->addDay());
        $previous['active_drivers'] = self::activeDrivers($today->subDays(2 * $days - 1), $today->subDays($days - 1));

        return ['current' => $current, 'previous' => $previous, 'daily' => $daily];
    }

    /** Drivers who synced at least one job in [$from, $to). */
    public static function activeDrivers(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) Delivery::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->distinct()
            ->count('user_id');
    }
}
