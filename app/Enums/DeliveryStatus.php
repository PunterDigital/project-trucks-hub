<?php

namespace App\Enums;

/** Mirrors ProjectTrucks' `jobs.status`. */
enum DeliveryStatus: string
{
    case Delivered = 'DELIVERED';
    case Late = 'LATE';
    case Cancelled = 'CANCELLED';

    /**
     * Statuses that count as a delivery (stats, leaderboards).
     *
     * @return list<string>
     */
    public static function counted(): array
    {
        return [self::Delivered->value, self::Late->value];
    }
}
