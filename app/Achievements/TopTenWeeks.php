<?php

namespace App\Achievements;

use App\Models\Delivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The "Top ten" leaders of each finished week (#5732), cached so a sync doesn't aggregate
 * every past week again. Each week holds the `SIZE` public drivers who drove furthest
 * (counted jobs, suspended drivers left out, as on the board): enough to tell whether fewer
 * than 10 *other* drivers drove further than anyone.
 *
 * A finished week changes only when a job in it changes (late sync, hide, delete, review) or
 * when a driver's privacy or suspension changes. `Delivery` and `User` forget the weeks they
 * touch; the TTL bounds anything a mass query would slip past.
 */
final class TopTenWeeks
{
    /** One more than the top ten: the driver asking may be among them. */
    public const SIZE = Achievement::TOP + 1;

    /** A day: whatever was missed heals by the next day's refresh. */
    public const TTL_SECONDS = 86_400;

    private const KEY = 'progress.top-ten.v1.';

    /** Monday 00:00 UTC of the week holding `$at`, as `Y-m-d`. */
    public static function weekOf(CarbonInterface|string $at): string
    {
        return CarbonImmutable::parse($at)->utc()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    /** Whether the week holding `$at` is over (only finished weeks count and are cached). */
    public static function isFinished(CarbonInterface|string $at): bool
    {
        return self::weekOf($at) < self::weekOf(CarbonImmutable::now('UTC'));
    }

    /**
     * The first of `$weeks` (week start => the driver's km, ascending) in which fewer than 10
     * other public drivers drove further. One cache read for all weeks; a week not cached
     * yet costs one aggregate, once a day site-wide.
     *
     * @param  array<string, float>  $weeks
     */
    public static function firstWeek(User $user, array $weeks): ?string
    {
        if ($weeks === []) {
            return null;
        }

        ksort($weeks);
        $cached = Cache::many(array_map(fn (string $w) => self::KEY.$w, array_keys($weeks)));
        $fresh = [];
        $found = null;

        foreach ($weeks as $week => $mine) {
            $leaders = $cached[self::KEY.$week] ?? null;
            if (! is_array($leaders)) {
                $leaders = $fresh[self::KEY.$week] = self::leaders($week);
            }

            $ahead = 0;
            foreach ($leaders as $userId => $km) {
                if ($userId !== $user->id && round((float) $km, 3) > round($mine, 3)) {
                    $ahead++;
                }
            }

            if ($ahead < Achievement::TOP) {
                $found = $week;
                break;
            }
        }

        if ($fresh !== []) {
            Cache::putMany($fresh, self::TTL_SECONDS);
        }

        return $found;
    }

    /**
     * The week's furthest public drivers, user id => km, as the driver leaderboard counts them.
     *
     * @return array<int, float>
     */
    public static function leaders(string $week): array
    {
        $start = CarbonImmutable::parse($week, 'UTC');

        return Delivery::query()->counted()->toBase()
            ->join('users', 'users.id', '=', 'deliveries.user_id')
            ->where('users.profile_private', false)
            ->where('deliveries.completed_at', '>=', $start)
            ->where('deliveries.completed_at', '<', $start->addWeek())
            ->groupBy('deliveries.user_id')
            ->selectRaw('deliveries.user_id, SUM(deliveries.distance_km) as km')
            ->orderByDesc('km')
            ->orderBy('deliveries.user_id')
            ->limit(self::SIZE)
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->user_id => (float) $row->km])
            ->all();
    }

    /** @var array<string, true> weeks waiting for the transaction to commit */
    private static array $pending = [];

    /**
     * Forgets the finished weeks holding these times (a job in them changed). After the
     * transaction commits, so a refresh elsewhere can't cache the old figures again, and once
     * per week however many of its jobs a backlog sync saves.
     */
    public static function forget(CarbonInterface|string|null ...$times): void
    {
        $first = self::$pending === [];
        foreach ($times as $at) {
            if ($at !== null && $at !== '' && self::isFinished($at)) {
                self::$pending[self::weekOf($at)] = true;
            }
        }

        if ($first && self::$pending !== []) {
            DB::afterCommit(function () {
                $weeks = array_keys(self::$pending);
                self::$pending = [];
                foreach ($weeks as $week) {
                    Cache::forget(self::KEY.$week);
                }
            });
        }
    }

    /** Forgets every finished week the driver has a job in (their privacy or suspension changed). */
    public static function forgetUser(User $user): void
    {
        $before = CarbonImmutable::parse(self::weekOf(CarbonImmutable::now('UTC')), 'UTC');

        self::forget(...$user->deliveries()
            ->where('completed_at', '<', $before)
            ->pluck('completed_at')
            ->map(fn ($at) => self::weekOf($at))
            ->unique()
            ->values()
            ->all());
    }
}
