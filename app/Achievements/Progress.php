<?php

namespace App\Achievements;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DriverAchievement;
use App\Models\User;
use App\Notifications\HubNotice;
use App\Support\CompanyRanks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Levels and achievements (#4645), worked out from a driver's counted jobs
 * ({@see Delivery::scopeEarned()}). `refresh()` is idempotent: it computes the whole set again
 * and stores the difference, so calling it after any change to a driver's jobs is safe.
 */
final class Progress
{
    /** Ranks, one per five levels; the last one has no ceiling. */
    public const RANKS = ['Rookie', 'Driver', 'Senior Driver', 'Road Captain', 'Veteran', 'Legend'];

    /** Distance needed to reach a level: 1,000 · (level − 1)^1.5 km, so level 2 is 1,000 km. */
    public static function kmForLevel(int $level): float
    {
        return 1000 * (max(1, $level) - 1) ** 1.5;
    }

    public static function level(float $km): int
    {
        $level = 1;
        while (self::kmForLevel($level + 1) <= $km) {
            $level++;
        }

        return $level;
    }

    public static function rank(int $level): string
    {
        return self::RANKS[min(intdiv(max(1, $level) - 1, 5), count(self::RANKS) - 1)];
    }

    /**
     * What a profile shows about progress.
     *
     * @return array<string, mixed>
     */
    public static function summary(User $user): array
    {
        $km = (float) $user->deliveries()->earned()->sum('distance_km');
        $level = self::level($km);
        $from = self::kmForLevel($level);
        $to = self::kmForLevel($level + 1);
        $earned = $user->achievements()->get()->keyBy(fn (DriverAchievement $a) => $a->achievement->value);

        return [
            'level' => $level,
            'rank' => self::rank($level),
            'distance_km' => round($km, 1),
            'next_level_km' => round($to, 1),
            'level_progress' => $to > $from ? round(($km - $from) / ($to - $from) * 100, 1) : 100,
            'achievements' => array_map(
                fn (Achievement $a) => $a->summary($earned->get($a->value)?->unlocked_at->toIso8601String()),
                Achievement::cases(),
            ),
        ];
    }

    /**
     * Works out the driver's level and achievements again and stores them. New achievements
     * ring the bell when `$notify` is on (not for the backfill).
     *
     * @return list<Achievement> the newly unlocked ones
     */
    public static function refresh(User $user, bool $notify = true): array
    {
        [$earned, $km, $jobs] = self::compute($user);

        $new = DB::transaction(function () use ($user, $earned, $km, $jobs) {
            $existing = $user->achievements()->get()->keyBy(fn (DriverAchievement $a) => $a->achievement->value);

            $user->achievements()
                ->whereNotIn('achievement', [...array_keys($earned), ...self::permanentKeys()])
                ->delete();

            $new = [];
            foreach ($earned as $key => $at) {
                if (! $existing->has($key)) {
                    $user->achievements()->create(['achievement' => $key, 'unlocked_at' => $at]);
                    $new[] = Achievement::from($key);
                }
            }

            // The driver directory sorts by these (#5734) instead of summing every driver's jobs.
            $user->forceFill([
                'level' => self::level($km),
                'earned_km' => round($km, 1),
                'earned_jobs' => $jobs,
            ])->saveQuietly();

            return $new;
        });

        if ($notify) {
            foreach ($new as $achievement) {
                $user->notify(HubNotice::achievementUnlocked($user, $achievement));
            }
        }

        // Company rank (#5223): every place that changes what counts runs through here. Silent:
        // only a job sync (JobSyncController) or a pin announces a promotion.
        CompanyRanks::refreshUser($user, notify: false);

        return $new;
    }

    /**
     * One pass over the driver's counted jobs: achievements, total km, and each finished
     * week's km for "Top ten" (#5732: no second sum, no query per week).
     *
     * @return array{array<string, Carbon|CarbonImmutable>, float, int} achievement key => when it was earned, the km and the job count
     */
    private static function compute(User $user): array
    {
        $earned = [];
        $earn = function (Achievement $a, Carbon|CarbonImmutable $at) use (&$earned) {
            $earned[$a->value] ??= $at;
        };

        $km = 0.0;
        $count = 0;
        $streak = 0;
        /** @var array<string, float> $weeks week start => km, finished weeks only */
        $weeks = [];
        $thisWeek = TopTenWeeks::weekOf(CarbonImmutable::now('UTC'));
        $jobs = $user->deliveries()->earned()
            ->orderBy('completed_at')->orderBy('id')
            ->toBase()
            ->select(['distance_km', 'cargo_mass_kg', 'status', 'completed_at'])
            ->cursor();

        foreach ($jobs as $job) {
            $at = Carbon::parse($job->completed_at);
            $km += (float) $job->distance_km;
            $week = TopTenWeeks::weekOf($at);
            if ($week < $thisWeek) {
                $weeks[$week] = ($weeks[$week] ?? 0.0) + (float) $job->distance_km;
            }
            $count++;
            $streak = $job->status === DeliveryStatus::Delivered->value ? $streak + 1 : 0;

            $earn(Achievement::FirstDelivery, $at);
            foreach ([[Achievement::Distance1k, 1000], [Achievement::Distance10k, 10000], [Achievement::Distance100k, 100000]] as [$a, $limit]) {
                if ($km >= $limit) {
                    $earn($a, $at);
                }
            }
            foreach ([[Achievement::Deliveries10, 10], [Achievement::Deliveries100, 100], [Achievement::Deliveries1000, 1000]] as [$a, $limit]) {
                if ($count >= $limit) {
                    $earn($a, $at);
                }
            }
            if ($streak >= Achievement::ON_TIME_STREAK) {
                $earn(Achievement::OnTimeStreak, $at);
            }
            if ((float) $job->distance_km >= Achievement::LONG_HAUL_KM) {
                $earn(Achievement::LongHaul, $at);
            }
            if ((float) $job->cargo_mass_kg >= Achievement::HEAVY_LOAD_KG) {
                $earn(Achievement::HeavyLoad, $at);
            }
        }

        if ($membership = $user->membership) {
            $earn(Achievement::JoinedCompany, $membership->created_at ?? now());
        }

        if ($week = self::firstTopTenWeek($user, $weeks)) {
            $earn(Achievement::WeeklyTopTen, $week);
        }

        return [$earned, $km, $count];
    }

    /**
     * The end of the first finished week (Monday to Monday, UTC) in which the driver was in the
     * driver leaderboard's top 10 by distance: fewer than 10 other public drivers drove further.
     * Private profiles aren't on that board, so they never get it; suspended drivers' rivals
     * are left out like on the board. Each week's leaders come from {@see TopTenWeeks} (one
     * cache read), so a sync no longer runs two aggregates per week driven (#5732).
     *
     * @param  array<string, float>  $weeks  week start => the driver's km
     */
    private static function firstTopTenWeek(User $user, array $weeks): ?CarbonImmutable
    {
        if ($user->profile_private) {
            return null;
        }

        $week = TopTenWeeks::firstWeek($user, $weeks);

        return $week === null ? null : CarbonImmutable::parse($week, 'UTC')->addWeek();
    }

    /** @return list<string> */
    private static function permanentKeys(): array
    {
        return array_values(array_map(
            fn (Achievement $a) => $a->value,
            array_filter(Achievement::cases(), fn (Achievement $a) => $a->permanent()),
        ));
    }
}
