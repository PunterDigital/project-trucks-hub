<?php

namespace App\Admin;

use App\Support\ImageStore;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Disk and storage use for the admin Storage page (#4667): the server disk that holds
 * `storage/`, uploads per kind, logs, the framework's file caches and the database, with a
 * daily snapshot per item in `storage_snapshots` for the 30-day charts and growth.
 */
final class StorageStats
{
    public const SNAPSHOT_TABLE = 'storage_snapshots';

    /** Snapshot items for the disk itself. */
    public const DISK_TOTAL = 'disk_total';

    public const DISK_FREE = 'disk_free';

    /** Warn when free space drops under this share of the disk... */
    public const LOW_FREE_RATIO = 0.10;

    /** ...or under this many bytes (5 GB). */
    public const LOW_FREE_BYTES = 5 * 1024 * 1024 * 1024;

    /** Snapshot keys of the upload folders, for the "Uploads" total. */
    public const UPLOADS = ['uploads_avatars', 'uploads_logos', 'uploads_banners', 'uploads_og'];

    /**
     * Every measured item: key => label. Add new ones at the end.
     *
     * @var array<string, string>
     */
    public const ITEMS = [
        'uploads_avatars' => 'Profile pictures',
        'uploads_logos' => 'Company logos',
        'uploads_banners' => 'Company banners',
        'uploads_og' => 'Link preview cards',
        'logs' => 'Logs',
        'cache' => 'Framework cache (files)',
        'sessions' => 'Sessions (files)',
        'views' => 'Compiled views',
        'database' => 'Database',
    ];

    /**
     * The disk that holds `storage/`. Null values when PHP can't tell (open_basedir etc.).
     *
     * @return array{total: int|null, free: int|null, used: int|null, low: bool}
     */
    public static function disk(): array
    {
        $path = storage_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        $total = $total === false ? null : (int) $total;
        $free = $free === false ? null : (int) $free;

        return [
            'total' => $total,
            'free' => $free,
            'used' => $total !== null && $free !== null ? $total - $free : null,
            'low' => $total !== null && $free !== null && self::isLow($total, $free),
        ];
    }

    /** Under 10% or under 5 GB free. */
    public static function isLow(int $total, int $free): bool
    {
        return $free < self::LOW_FREE_BYTES || ($total > 0 && $free / $total < self::LOW_FREE_RATIO);
    }

    /**
     * Each item's size now. `files` is null for the database.
     *
     * @return list<array{key: string, label: string, bytes: int|null, files: int|null}>
     */
    public static function items(): array
    {
        $public = Storage::disk(ImageStore::DISK);
        $rows = [];

        foreach (self::ITEMS as $key => $label) {
            if ($key === 'database') {
                $rows[] = ['key' => $key, 'label' => $label, 'bytes' => DatabaseStats::totalBytes(), 'files' => null];

                continue;
            }

            $path = match ($key) {
                'uploads_avatars' => $public->path('avatars'),
                'uploads_logos' => $public->path('logos'),
                'uploads_banners' => $public->path('banners'),
                'uploads_og' => $public->path('og'),
                'logs' => storage_path('logs'),
                'cache' => storage_path('framework/cache'),
                'sessions' => storage_path('framework/sessions'),
                default => storage_path('framework/views'),
            };

            $rows[] = ['key' => $key, 'label' => $label, ...self::directory($path)];
        }

        return $rows;
    }

    /**
     * Total size and file count under $path (recursive; `.gitignore` placeholders left out).
     * A missing or unreadable folder counts as empty.
     *
     * @return array{bytes: int, files: int}
     */
    public static function directory(string $path): array
    {
        $bytes = 0;
        $files = 0;

        if (! is_dir($path)) {
            return ['bytes' => 0, 'files' => 0];
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                    $bytes += (int) $file->getSize();
                    $files++;
                }
            }
        } catch (Throwable) {
            // A folder that vanishes or can't be read mid-walk: report what was counted.
        }

        return ['bytes' => $bytes, 'files' => $files];
    }

    /**
     * Store today's snapshot unless there is one. Returns whether it wrote.
     */
    public static function snapshot(bool $force = false): bool
    {
        $date = CarbonImmutable::now('UTC')->toDateString();

        if (! $force && DB::table(self::SNAPSHOT_TABLE)->where('date', $date)->exists()) {
            return false;
        }

        $now = CarbonImmutable::now();
        $disk = self::disk();
        $row = fn (string $item, ?int $bytes, ?int $files): array => [
            'date' => $date, 'item' => $item, 'bytes' => $bytes, 'files' => $files,
            'created_at' => $now, 'updated_at' => $now,
        ];

        $rows = [$row(self::DISK_TOTAL, $disk['total'], null), $row(self::DISK_FREE, $disk['free'], null)];

        foreach (self::items() as $item) {
            $rows[] = $row($item['key'], $item['bytes'], $item['files']);
        }

        DB::table(self::SNAPSHOT_TABLE)->upsert($rows, ['date', 'item'], ['bytes', 'files', 'updated_at']);

        return true;
    }

    /**
     * Items now with their growth since the latest snapshot on or before 30 days ago (null =
     * no snapshot that old, or the size isn't known).
     *
     * @return list<array{key: string, label: string, bytes: int|null, files: int|null, growth_30: int|null}>
     */
    public static function itemsWithGrowth(): array
    {
        $cutoff = CarbonImmutable::now('UTC')->startOfDay()->subDays(30)->toDateString();
        $date = DB::table(self::SNAPSHOT_TABLE)->where('date', '<=', $cutoff)->max('date');
        $then = $date === null ? [] : DB::table(self::SNAPSHOT_TABLE)->where('date', $date)->pluck('bytes', 'item')->all();

        return array_map(function (array $item) use ($then): array {
            $old = $then[$item['key']] ?? null;

            return $item + ['growth_30' => $old === null || $item['bytes'] === null ? null : $item['bytes'] - (int) $old];
        }, self::items());
    }

    /**
     * Bytes per snapshot day over the last $days days for each series: disk used, uploads
     * (all kinds), database and logs. Days without a snapshot are left out.
     *
     * @return array<string, array{disk_used: int|null, uploads: int, database: int|null, logs: int|null}>
     */
    public static function series(int $days): array
    {
        $from = CarbonImmutable::now('UTC')->startOfDay()->subDays($days - 1)->toDateString();
        $series = [];

        $rows = DB::table(self::SNAPSHOT_TABLE)->where('date', '>=', $from)->orderBy('date')->get(['date', 'item', 'bytes']);

        foreach ($rows->groupBy(fn (object $row): string => substr((string) $row->date, 0, 10)) as $date => $day) {
            $bytes = $day->mapWithKeys(fn (object $row): array => [(string) $row->item => $row->bytes === null ? null : (int) $row->bytes]);
            $total = $bytes->get(self::DISK_TOTAL);
            $free = $bytes->get(self::DISK_FREE);

            $series[(string) $date] = [
                'disk_used' => $total !== null && $free !== null ? $total - $free : null,
                'uploads' => (int) collect(self::UPLOADS)->sum(fn (string $key): int => (int) $bytes->get($key)),
                'database' => $bytes->get('database'),
                'logs' => $bytes->get('logs'),
            ];
        }

        return $series;
    }
}
