<?php

namespace App\Achievements;

/**
 * Driver achievements (#4645). Each is worked out from the driver's counted jobs by
 * {@see Progress::refresh()}; `driver_achievements` stores the key and when it was earned.
 * Add new cases at the end: the order is the order on the profile.
 */
enum Achievement: string
{
    case FirstDelivery = 'first_delivery';
    case Distance1k = 'distance_1k';
    case Distance10k = 'distance_10k';
    case Distance100k = 'distance_100k';
    case Deliveries10 = 'deliveries_10';
    case Deliveries100 = 'deliveries_100';
    case Deliveries1000 = 'deliveries_1000';
    case OnTimeStreak = 'on_time_streak_20';
    case LongHaul = 'long_haul_1500';
    case HeavyLoad = 'heavy_load_20t';
    case JoinedCompany = 'joined_company';
    case WeeklyTopTen = 'weekly_top_10';

    public const ON_TIME_STREAK = 20;

    public const LONG_HAUL_KM = 1500;

    public const HEAVY_LOAD_KG = 20000;

    public const TOP = 10;

    public function title(): string
    {
        return match ($this) {
            self::FirstDelivery => 'First delivery',
            self::Distance1k => '1,000 km',
            self::Distance10k => '10,000 km',
            self::Distance100k => '100,000 km',
            self::Deliveries10 => '10 deliveries',
            self::Deliveries100 => '100 deliveries',
            self::Deliveries1000 => '1,000 deliveries',
            self::OnTimeStreak => 'Clockwork',
            self::LongHaul => 'Long haul',
            self::HeavyLoad => 'Heavy load',
            self::JoinedCompany => 'Team player',
            self::WeeklyTopTen => 'Top ten',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FirstDelivery => 'Deliver your first job.',
            self::Distance1k => 'Drive 1,000 km on delivered jobs.',
            self::Distance10k => 'Drive 10,000 km on delivered jobs.',
            self::Distance100k => 'Drive 100,000 km on delivered jobs.',
            self::Deliveries10 => 'Deliver 10 jobs.',
            self::Deliveries100 => 'Deliver 100 jobs.',
            self::Deliveries1000 => 'Deliver 1,000 jobs.',
            self::OnTimeStreak => 'Deliver 20 jobs in a row on time.',
            self::LongHaul => 'Deliver a single job of 1,500 km or more.',
            self::HeavyLoad => 'Deliver a cargo of 20 t or more.',
            self::JoinedCompany => 'Join or found a company.',
            self::WeeklyTopTen => 'Finish a week in the top 10 of the driver leaderboard (distance).',
        };
    }

    /** Kept even if the reason for it goes away (leaving the company). */
    public function permanent(): bool
    {
        return $this === self::JoinedCompany;
    }

    /** @return array<string, mixed> */
    public function summary(?string $unlockedAt): array
    {
        return [
            'key' => $this->value,
            'title' => $this->title(),
            'description' => $this->description(),
            'unlocked_at' => $unlockedAt,
        ];
    }
}
