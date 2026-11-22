<?php

namespace App\Sync;

use App\Admin\Moderation;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Hub's own anti-cheat checks on a synced job (#4642). ProjectTrucks flags implausible
 * jobs itself, but a modified client could send anything, so the Hub re-checks every job it
 * receives. Thresholds live in `config/anticheat.php`.
 *
 * Only jobs that could count (delivered or late) are checked: a cancelled job never reaches
 * stats, and the app never shows a cancel as flagged. Cancels still add their driving time
 * to the day's total.
 */
class JobChecks
{
    /**
     * Reasons the job fails, empty when it passes. `$held` is true when the only problem is
     * the rate check (too many jobs in an hour): those are held for review rather than judged.
     *
     * The job's other rows (rate and daily totals) are read from the database, so call this
     * before saving it but after earlier jobs in the same batch were saved.
     *
     * @return array{reasons: list<string>, held: bool}
     */
    public static function run(Delivery $job, ?Carbon $now = null): array
    {
        if (! in_array($job->status->value, DeliveryStatus::counted(), true)) {
            return ['reasons' => [], 'held' => false];
        }

        $now ??= now();
        $config = config('anticheat');
        $reasons = [];

        $maxSpeed = (float) $config['max_avg_speed_kmh'];
        $minKm = (float) $config['min_delivery_km'];
        $distance = (float) $job->distance_km;
        $duration = (int) $job->duration_sec;

        if ($distance < $minKm) {
            $reasons[] = sprintf('Distance under %s km', self::num($minKm));
        }

        // Kilometres per real hour, and per in-game hour when the app sent the game time
        // (#4771). The game's odometer runs on game time, so only the game-time average is a
        // truck speed; apps before 0.11 report distance / real time and get the real-time
        // limit alone.
        $realSpeed = $duration > 0 ? $distance / ($duration / 3600) : null;
        $gameMinutes = $job->game_duration_min;
        $gameSpeed = $gameMinutes !== null && $gameMinutes > 0 ? $distance / ($gameMinutes / 60) : null;
        $computedSpeed = $gameMinutes !== null ? $gameSpeed : $realSpeed;
        $reportedSpeed = (float) $job->avg_speed_kmh;

        $tooFast = $gameMinutes !== null
            && ($reportedSpeed > $maxSpeed || ($gameSpeed !== null && $gameSpeed > $maxSpeed));
        $maxReal = (float) $config['max_real_km_per_hour'];
        if ($tooFast || ($realSpeed !== null && $realSpeed > $maxReal)) {
            $reasons[] = sprintf('Average speed over %s km/h', self::num($maxSpeed));
        }

        if (self::inconsistent($job, $distance, $duration, $computedSpeed, $reportedSpeed, $minKm)) {
            $reasons[] = 'Distance and driving time don\'t match';
        }

        $maxIncome = (int) $config['income_flat'] + $distance * (float) $config['income_per_km'];
        if ($job->effectiveIncome() > $maxIncome) {
            $reasons[] = 'Income too high for the distance';
        }

        if ($job->completed_at->greaterThan($now->copy()->addSeconds((int) $config['future_slack_sec']))) {
            $reasons[] = 'Completed in the future';
        }

        if (self::drivingThatDay($job) + $duration > (int) $config['max_driving_sec_per_day']) {
            $reasons[] = 'More than 24 h driven in one day';
        }

        if ($reasons === []) {
            $max = (int) $config['max_jobs_per_hour'];
            if (self::others($job)
                ->whereIn('status', DeliveryStatus::counted())
                ->whereBetween('completed_at', [$job->completed_at->copy()->subHour(), $job->completed_at])
                ->count() + 1 > $max) {
                return ['reasons' => [sprintf('Held for review: more than %d jobs in an hour', $max)], 'held' => true];
            }
        }

        return ['reasons' => $reasons, 'held' => false];
    }

    /**
     * Check again every job the Hub flagged for its average speed (#4771: the old check divided
     * game kilometres by real time, so it caught nearly every recorded job). Staff decisions
     * stay as they are; a job that still fails keeps a flag with today's reasons. Returns how
     * many jobs lost their flag.
     */
    public static function recheckSpeedFlags(): int
    {
        $cleared = 0;
        Delivery::query()
            ->where('hub_flag_reason', 'like', '%Average speed over%')
            ->where('hub_flag_reason', 'not like', Moderation::STAFF_FLAG_PREFIX.'%')
            ->where('staff_reviewed', false)
            ->orderBy('id')
            ->each(function (Delivery $job) use (&$cleared) {
                $check = self::run($job);
                $job->forceFill([
                    'hub_flag_reason' => $check['reasons'] === [] ? null : implode('; ', $check['reasons']),
                    'hub_held' => $check['held'],
                ])->save();
                if ($check['reasons'] === []) {
                    $cleared++;
                }
            });

        return $cleared;
    }

    private static function inconsistent(
        Delivery $job,
        float $distance,
        int $duration,
        ?float $computedSpeed,
        float $reportedSpeed,
        float $minKm,
    ): bool {
        $config = config('anticheat');

        // Delivered before it started, or driven for longer than the job lasted.
        $span = $job->started_at->diffInSeconds($job->completed_at, false);
        if ($span < 0 || $duration > $span + (int) $config['duration_slack_sec']) {
            return true;
        }

        // Kilometres with no time to drive them (no real time, or a game time of zero).
        if ($computedSpeed === null) {
            return $distance >= $minKm;
        }

        // The reported average isn't distance / (game) time.
        $allowed = max((float) $config['speed_mismatch_kmh'], $computedSpeed * (float) $config['speed_mismatch_ratio']);

        return abs($reportedSpeed - $computedSpeed) > $allowed;
    }

    /** Seconds the driver already drove on the job's UTC day, from their other jobs. */
    private static function drivingThatDay(Delivery $job): int
    {
        $day = $job->completed_at->copy()->utc();

        return (int) self::others($job)
            ->whereBetween('completed_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->sum('duration_sec');
    }

    /** @return Builder<Delivery> */
    private static function others(Delivery $job): Builder
    {
        return Delivery::query()
            ->where('user_id', $job->user_id)
            ->when($job->exists, fn (Builder $q) => $q->whereKeyNot($job->getKey()));
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
