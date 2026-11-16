<?php

namespace App\Admin;

use App\Models\DeviceAuthorization;
use App\Models\LiveStatus;
use Carbon\CarbonImmutable;

/**
 * Clearing out data nobody needs any more (#4667). Staff run each from the admin Storage page
 * (audited there); `hub:prune` runs them all daily. Nothing here touches a driver's jobs,
 * profile or uploads.
 */
final class Housekeeping
{
    /** A live status not updated for this long is gone for good (offline after 2 minutes). */
    public const LIVE_STATUS_STALE_HOURS = 24;

    /** Log files last written longer ago than this are deleted. */
    public const LOG_KEEP_DAYS = 14;

    /**
     * Link requests that expired over a day ago. The last two closed days are rolled up into
     * the dashboard's daily stats first, so their counts aren't lost (#4665).
     */
    public static function pruneLinkRequests(): int
    {
        DailyStats::fill(CarbonImmutable::now()->subDays(2));

        return DeviceAuthorization::query()->where('expires_at', '<', now()->subDay())->delete();
    }

    /** Live statuses the app stopped updating a day ago (game closed without saying so). */
    public static function pruneLiveStatuses(): int
    {
        return LiveStatus::query()->where('updated_at', '<', now()->subHours(self::LIVE_STATUS_STALE_HOURS))->delete();
    }

    /**
     * `*.log` files in the logs folder last written more than {@see self::LOG_KEEP_DAYS} days
     * ago (rotated daily logs). The log being written today is never old enough.
     *
     * @return array{files: int, bytes: int}
     */
    public static function clearOldLogs(?string $dir = null): array
    {
        $dir ??= storage_path('logs');
        $cutoff = now()->subDays(self::LOG_KEEP_DAYS)->getTimestamp();
        $files = 0;
        $bytes = 0;

        foreach (glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*.log') ?: [] as $path) {
            $mtime = @filemtime($path);
            $size = @filesize($path);

            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $files++;
                $bytes += $size === false ? 0 : $size;
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }
}
