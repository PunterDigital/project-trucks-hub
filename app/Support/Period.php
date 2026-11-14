<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Leaderboard and stats windows. Weeks start on Monday; all boundaries are UTC. */
enum Period: string
{
    case Week = 'week';
    case Month = 'month';
    case All = 'all';

    public function start(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now ??= CarbonImmutable::now('UTC');

        return match ($this) {
            self::Week => $now->startOfWeek(CarbonImmutable::MONDAY),
            self::Month => $now->startOfMonth(),
            self::All => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Week => 'This week',
            self::Month => 'This month',
            self::All => 'All time',
        };
    }
}
