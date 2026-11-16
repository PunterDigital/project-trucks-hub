<?php

namespace App\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Cache;

/**
 * Whether Laravel's scheduler runs on this server, for the admin Scheduler page (#4666).
 *
 * `schedule:run` (cron, every minute) writes a heartbeat as it starts, and every scheduled
 * task's finish or failure is recorded with its time and run time. Listeners are in
 * `AppServiceProvider`. Both live in the cache (the database store in production), so they
 * survive deploys but not a cache clear.
 */
final class SchedulerStatus
{
    public const HEARTBEAT_KEY = 'hub:scheduler:heartbeat';

    public const RUNS_KEY = 'hub:scheduler:runs';

    /** The page warns once the last heartbeat is older than this. */
    public const LATE_AFTER_MINUTES = 10;

    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, CarbonImmutable::now()->getTimestamp());
    }

    public static function lastBeat(): ?CarbonImmutable
    {
        $ts = Cache::get(self::HEARTBEAT_KEY);

        return is_numeric($ts) ? CarbonImmutable::createFromTimestamp((int) $ts) : null;
    }

    /** True when the scheduler has never run here or hasn't for 10 minutes. */
    public static function isLate(): bool
    {
        $last = self::lastBeat();

        return $last === null || $last->lessThan(CarbonImmutable::now()->subMinutes(self::LATE_AFTER_MINUTES));
    }

    public static function record(Event $task, bool $ok, ?float $runtime): void
    {
        $runs = self::runs();
        $runs[self::name($task)] = [
            'task' => self::name($task),
            'schedule' => $task->expression,
            'ran_at' => CarbonImmutable::now()->getTimestamp(),
            'ok' => $ok,
            'runtime' => $runtime === null ? null : round($runtime, 2),
        ];

        Cache::forever(self::RUNS_KEY, $runs);
    }

    /**
     * The last run of each task that has run here, keyed by task name.
     *
     * @return array<string, array{task: string, schedule: string, ran_at: int, ok: bool, runtime: float|null}>
     */
    public static function runs(): array
    {
        $runs = Cache::get(self::RUNS_KEY);

        return is_array($runs) ? $runs : [];
    }

    /** "hub:progress --notify" rather than the full PHP binary path and quoting. */
    public static function name(Event $task): string
    {
        if (filled($task->description)) {
            return (string) $task->description;
        }

        $command = (string) $task->command;
        $short = preg_replace('/^.*?artisan[\'"]?\s+/', '', $command);

        return trim((string) ($short ?? $command));
    }
}
