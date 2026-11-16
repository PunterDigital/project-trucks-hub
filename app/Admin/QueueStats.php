<?php

namespace App\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Laravel queue for the admin Queue page (#4666).
 *
 * Waiting jobs are read from the database queue's `jobs` table. Throughput and run times are
 * counted by the worker itself: `AppServiceProvider` passes JobProcessing / JobProcessed /
 * JobFailed here, and each finished job adds to its hour's row in `queue_job_stats`. Jobs on
 * the `sync` connection (the bell's notifications) run inside a request and aren't counted.
 */
final class QueueStats
{
    public const TABLE = 'queue_job_stats';

    /** Jobs waiting longer than this mean no worker is taking them. */
    public const STUCK_AFTER_MINUTES = 5;

    /** @var array<string, int> start times (hrtime ns) of the jobs this process is running */
    private static array $started = [];

    public static function started(string $connection, Job $job): void
    {
        if ($connection !== 'sync') {
            self::$started[self::key($connection, $job)] = hrtime(true);
        }
    }

    /** Count a finished job. Never throws: the stats must not fail the job. */
    public static function finished(string $connection, Job $job, bool $failed): void
    {
        if ($connection === 'sync') {
            return;
        }

        $key = self::key($connection, $job);
        $ms = isset(self::$started[$key]) ? (int) round((hrtime(true) - self::$started[$key]) / 1_000_000) : 0;
        unset(self::$started[$key]);

        try {
            $now = CarbonImmutable::now();
            $key = [
                'hour' => $now->utc()->startOfHour(),
                'job_class' => mb_substr($job->resolveName(), 0, 190),
                'queue' => mb_substr((string) $job->getQueue(), 0, 60),
            ];

            // Make sure the hour's row exists, then add to it in one UPDATE (two workers
            // finishing at once both count).
            DB::table(self::TABLE)->insertOrIgnore($key + ['created_at' => $now, 'updated_at' => $now]);
            DB::table(self::TABLE)->where($key)->incrementEach(
                ['processed' => $failed ? 0 : 1, 'failed' => $failed ? 1 : 0, 'total_ms' => $ms],
                ['updated_at' => $now],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function key(string $connection, Job $job): string
    {
        return $connection.':'.$job->getJobId();
    }

    /**
     * Jobs in the database queue per queue name: waiting (not reserved), running (reserved by
     * a worker) and how long the oldest waiting one has been due.
     *
     * @return list<array{queue: string, waiting: int, running: int, oldest_seconds: int|null}>
     */
    public static function waiting(): array
    {
        $table = (string) config('queue.connections.database.table', 'jobs');
        $now = CarbonImmutable::now()->getTimestamp();

        return array_values(DB::table($table)
            ->selectRaw('queue, sum(case when reserved_at is null then 1 else 0 end) as waiting, '
                .'sum(case when reserved_at is null then 0 else 1 end) as running, '
                .'min(case when reserved_at is null then available_at end) as oldest')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(function (object $row) use ($now): array {
                /** @var array{queue: string, waiting: int|string, running: int|string, oldest: int|string|null} $r */
                $r = (array) $row;

                return [
                    'queue' => (string) $r['queue'],
                    'waiting' => (int) $r['waiting'],
                    'running' => (int) $r['running'],
                    'oldest_seconds' => $r['oldest'] === null ? null : max(0, $now - (int) $r['oldest']),
                ];
            })
            ->values()
            ->all());
    }

    /** True when a job has been due for over 5 minutes and nothing has picked it up. */
    public static function workerLooksDown(): bool
    {
        foreach (self::waiting() as $queue) {
            if ($queue['oldest_seconds'] !== null && $queue['oldest_seconds'] > self::STUCK_AFTER_MINUTES * 60) {
                return true;
            }
        }

        return false;
    }

    /**
     * Processed and failed jobs per hour over the last $hours hours (this hour included), oldest
     * first.
     *
     * @return array{hours: list<string>, processed: list<int>, failed: list<int>}
     */
    public static function hourly(int $hours = 48): array
    {
        $current = CarbonImmutable::now('UTC')->startOfHour();
        $from = $current->subHours($hours - 1);

        $rows = DB::table(self::TABLE)
            ->where('hour', '>=', $from)
            ->selectRaw('hour, sum(processed) as processed, sum(failed) as failed')
            ->groupBy('hour')
            ->get()
            ->mapWithKeys(function (object $row): array {
                /** @var array{hour: string, processed: int|string, failed: int|string} $r */
                $r = (array) $row;

                return [CarbonImmutable::parse($r['hour'], 'UTC')->format('Y-m-d H') => [(int) $r['processed'], (int) $r['failed']]];
            });

        $out = ['hours' => [], 'processed' => [], 'failed' => []];

        for ($hour = $from; $hour->lessThanOrEqualTo($current); $hour = $hour->addHour()) {
            $out['hours'][] = $hour->format('Y-m-d H:00');
            $out['processed'][] = $rows[$hour->format('Y-m-d H')][0] ?? 0;
            $out['failed'][] = $rows[$hour->format('Y-m-d H')][1] ?? 0;
        }

        return $out;
    }

    /** @return array{processed: int, failed: int} jobs finished in the last 24 hours */
    public static function lastDay(): array
    {
        $hourly = self::hourly(24);

        return ['processed' => (int) array_sum($hourly['processed']), 'failed' => (int) array_sum($hourly['failed'])];
    }

    /**
     * Runs and average run time per job class over the last $days days, busiest first.
     *
     * @return list<array{job_class: string, runs: int, failed: int, avg_ms: int}>
     */
    public static function runtimes(int $days = 7): array
    {
        return array_values(DB::table(self::TABLE)
            ->where('hour', '>=', CarbonImmutable::now('UTC')->startOfHour()->subDays($days))
            ->selectRaw('job_class, sum(processed) as processed, sum(failed) as failed, sum(total_ms) as total_ms')
            ->groupBy('job_class')
            ->get()
            ->map(function (object $row): array {
                /** @var array{job_class: string, processed: int|string, failed: int|string, total_ms: int|string} $r */
                $r = (array) $row;
                $runs = (int) $r['processed'] + (int) $r['failed'];

                return [
                    'job_class' => (string) $r['job_class'],
                    'runs' => $runs,
                    'failed' => (int) $r['failed'],
                    'avg_ms' => $runs === 0 ? 0 : (int) round((int) $r['total_ms'] / $runs),
                ];
            })
            ->sortByDesc('runs')
            ->values()
            ->all());
    }
}
