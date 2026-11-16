<?php

namespace App\Admin;

use App\Models\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sync API traffic for the admin Data usage page (#4667): one `api_usage_stats` row per UTC
 * hour and endpoint (the route name without `api.`), filled by `RecordApiUsage`.
 */
final class ApiUsage
{
    public const TABLE = 'api_usage_stats';

    /** Counter columns, in the order the tables show them. */
    public const COUNTERS = [
        'requests', 'bytes_in', 'throttled', 'errors',
        'jobs_synced', 'jobs_skipped', 'jobs_flagged', 'jobs_rejected',
    ];

    /** Add one request to its hour. Never throws: the stats must not fail the API. */
    public static function record(Request $request, Response $response): void
    {
        try {
            $name = $request->route()?->getName();
            $endpoint = is_string($name) ? mb_substr((string) preg_replace('/^api\./', '', $name), 0, 40) : 'unmatched';
            $status = $response->getStatusCode();
            $length = $request->headers->get('Content-Length');

            $add = array_fill_keys(self::COUNTERS, 0);
            $add['requests'] = 1;
            $add['bytes_in'] = is_numeric($length) ? (int) $length : strlen((string) $request->getContent());
            $add['throttled'] = $status === 429 ? 1 : 0;
            $add['errors'] = $status >= 400 && $status !== 429 ? 1 : 0;

            if ($endpoint === 'jobs.store') {
                $add = array_merge($add, self::jobCounts($request, $response));
            }

            $now = CarbonImmutable::now();
            $key = ['hour' => $now->utc()->startOfHour(), 'endpoint' => $endpoint];

            // Make sure the hour's row exists, then add to it in one UPDATE (parallel requests
            // both count).
            DB::table(self::TABLE)->insertOrIgnore($key + ['created_at' => $now, 'updated_at' => $now]);
            DB::table(self::TABLE)->where($key)->incrementEach($add, ['updated_at' => $now]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Jobs in a sync batch: stored (`synced`), dropped (`skipped`, manual jobs), stored with a
     * Hub flag, or refused (a batch the API answered with an error: every job in it).
     *
     * @return array{jobs_synced: int, jobs_skipped: int, jobs_flagged: int, jobs_rejected: int}
     */
    private static function jobCounts(Request $request, Response $response): array
    {
        $counts = ['jobs_synced' => 0, 'jobs_skipped' => 0, 'jobs_flagged' => 0, 'jobs_rejected' => 0];

        if (! $response->isSuccessful()) {
            $jobs = $request->input('jobs');
            $counts['jobs_rejected'] = is_array($jobs) ? count($jobs) : 0;

            return $counts;
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $jobs = is_array($data['jobs'] ?? null) ? $data['jobs'] : [];
            $counts['jobs_synced'] = count($jobs);
            $counts['jobs_skipped'] = is_array($data['skipped'] ?? null) ? count($data['skipped']) : 0;
            $counts['jobs_flagged'] = count(array_filter($jobs, fn ($job): bool => is_array($job) && ($job['hub_flag_reason'] ?? null) !== null));
        }

        return $counts;
    }

    /**
     * Totals over the last $hours hours.
     *
     * @return array<string, int>
     */
    public static function totals(int $hours = 24): array
    {
        $from = CarbonImmutable::now()->utc()->startOfHour()->subHours($hours - 1);
        $row = (array) DB::table(self::TABLE)->where('hour', '>=', $from)
            ->selectRaw(implode(', ', array_map(fn (string $c): string => "coalesce(sum({$c}), 0) as {$c}", self::COUNTERS)))
            ->first();

        return array_map('intval', array_intersect_key($row, array_flip(self::COUNTERS)));
    }

    /**
     * Per UTC day over the last $days days (missing days are zero): all requests, live-status
     * updates, 429s and bytes received.
     *
     * @return array<string, array{requests: int, status_updates: int, throttled: int, bytes_in: int}>
     */
    public static function daily(int $days): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $series = [];

        for ($d = $days - 1; $d >= 0; $d--) {
            $series[$today->subDays($d)->toDateString()] = ['requests' => 0, 'status_updates' => 0, 'throttled' => 0, 'bytes_in' => 0];
        }

        // Grouped in PHP, not with SQL date functions, so SQLite and MySQL agree.
        $rows = DB::table(self::TABLE)->where('hour', '>=', $today->subDays($days - 1))
            ->get(['hour', 'endpoint', 'requests', 'throttled', 'bytes_in']);

        foreach ($rows as $row) {
            $date = substr((string) $row->hour, 0, 10);

            if (! isset($series[$date])) {
                continue;
            }

            $series[$date]['requests'] += (int) $row->requests;
            $series[$date]['throttled'] += (int) $row->throttled;
            $series[$date]['bytes_in'] += (int) $row->bytes_in;
            if ($row->endpoint === 'status.update') {
                $series[$date]['status_updates'] += (int) $row->requests;
            }
        }

        return $series;
    }

    /**
     * Each endpoint's totals over the last $days days, busiest first.
     *
     * @return list<array<string, int|string>>
     */
    public static function byEndpoint(int $days): array
    {
        $from = CarbonImmutable::now('UTC')->startOfDay()->subDays($days - 1);

        return array_values(DB::table(self::TABLE)->where('hour', '>=', $from)
            ->groupBy('endpoint')
            ->selectRaw('endpoint, '.implode(', ', array_map(fn (string $c): string => "sum({$c}) as {$c}", self::COUNTERS)))
            ->orderByDesc('requests')
            ->get()
            ->map(fn (object $row): array => ['endpoint' => (string) $row->endpoint]
                + array_map('intval', array_intersect_key((array) $row, array_flip(self::COUNTERS))))
            ->all());
    }

    /**
     * The $limit drivers holding the most jobs, with a size estimate: their share of the
     * `deliveries` table where its size is known (MySQL), else null.
     *
     * @return list<array{user_id: int, name: string, username: string|null, jobs: int, bytes: int|null}>
     */
    public static function topDrivers(int $limit = 20): array
    {
        $table = collect(DatabaseStats::tables())->firstWhere('name', 'deliveries');
        $perRow = $table !== null && $table['total_bytes'] !== null && $table['rows'] > 0
            ? $table['total_bytes'] / $table['rows']
            : null;

        return array_values(Delivery::query()
            ->join('users', 'users.id', '=', 'deliveries.user_id')
            ->groupBy('deliveries.user_id', 'users.name', 'users.username')
            ->selectRaw('deliveries.user_id, users.name, users.username, count(*) as jobs')
            ->orderByDesc('jobs')
            ->orderBy('deliveries.user_id')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'username' => $row->username === null ? null : (string) $row->username,
                'jobs' => (int) $row->jobs,
                'bytes' => $perRow === null ? null : (int) round($perRow * (int) $row->jobs),
            ])
            ->all());
    }
}
