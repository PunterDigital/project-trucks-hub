<?php

namespace App\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Database health for the admin panel (#4666): per-table rows and sizes, the connection, and
 * a daily snapshot per table in `db_table_snapshots` so growth can be shown.
 *
 * MySQL/MariaDB read `information_schema.TABLES` (rows there are InnoDB's estimate). Other
 * drivers (SQLite in tests and E2E) use Laravel's `Schema::getTables()` for the size, which
 * is only known where SQLite has `dbstat`, plus an exact `COUNT(*)` per table.
 * Nothing here returns a host, user name or password.
 */
final class DatabaseStats
{
    public const SNAPSHOT_TABLE = 'db_table_snapshots';

    /** The snapshot row that holds the whole database's size. */
    public const TOTAL = '*';

    public static function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Every table, largest first (by size where known, then by rows).
     *
     * @return list<array{name: string, rows: int, data_bytes: int|null, index_bytes: int|null, total_bytes: int|null}>
     */
    public static function tables(): array
    {
        if (self::isMysql()) {
            $rows = DB::select(
                'select TABLE_NAME as name, TABLE_ROWS as row_count, DATA_LENGTH as data_bytes, INDEX_LENGTH as index_bytes '
                ."from information_schema.TABLES where TABLE_SCHEMA = DATABASE() and TABLE_TYPE = 'BASE TABLE'",
            );

            $tables = array_values(array_map(function (mixed $row): array {
                /** @var array{name: string, row_count: int|string|null, data_bytes: int|string|null, index_bytes: int|string|null} $t */
                $t = (array) $row;

                return [
                    'name' => (string) $t['name'],
                    'rows' => (int) $t['row_count'],
                    'data_bytes' => (int) $t['data_bytes'],
                    'index_bytes' => (int) $t['index_bytes'],
                    'total_bytes' => (int) $t['data_bytes'] + (int) $t['index_bytes'],
                ];
            }, $rows));
        } else {
            $tables = array_map(function (array $t): array {
                $size = isset($t['size']) ? (int) $t['size'] : null;

                return [
                    'name' => (string) $t['name'],
                    'rows' => DB::table((string) $t['name'])->count(),
                    'data_bytes' => $size,
                    'index_bytes' => null,
                    'total_bytes' => $size,
                ];
            }, Schema::getTables(Schema::getCurrentSchemaName()));
        }

        usort($tables, fn (array $a, array $b): int => [$b['total_bytes'] ?? 0, $b['rows']] <=> [$a['total_bytes'] ?? 0, $a['rows']]);

        return $tables;
    }

    /** The whole database's size in bytes, or null when the driver can't say. */
    public static function totalBytes(): ?int
    {
        if (self::isMysql()) {
            return (int) DB::scalar(
                'select coalesce(sum(DATA_LENGTH + INDEX_LENGTH), 0) from information_schema.TABLES where TABLE_SCHEMA = DATABASE()',
            );
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return (int) DB::scalar('select page_count * page_size from pragma_page_count(), pragma_page_size()');
        }

        $sizes = array_filter(array_column(self::tables(), 'total_bytes'), fn ($s) => $s !== null);

        return $sizes === [] ? null : (int) array_sum($sizes);
    }

    /**
     * Driver, server version, uptime and open connections. Never the host or credentials.
     *
     * @return array{driver: string, version: string|null, uptime_seconds: int|null, connections: int|null}
     */
    public static function connection(): array
    {
        $driver = DB::connection()->getDriverName();
        $info = ['driver' => $driver, 'version' => null, 'uptime_seconds' => null, 'connections' => null];

        try {
            if (self::isMysql()) {
                $info['version'] = (string) DB::scalar('select version()');
                $status = collect(DB::select("show global status where Variable_name in ('Uptime', 'Threads_connected')"))
                    ->mapWithKeys(function (mixed $row): array {
                        /** @var array{Variable_name: string, Value: int|string} $r */
                        $r = (array) $row;

                        return [(string) $r['Variable_name'] => (int) $r['Value']];
                    });
                $info['uptime_seconds'] = $status->get('Uptime');
                $info['connections'] = $status->get('Threads_connected');
            } elseif ($driver === 'sqlite') {
                $info['version'] = (string) DB::scalar('select sqlite_version()');
            } else {
                $info['version'] = DB::connection()->getServerVersion();
            }
        } catch (Throwable) {
            // A missing privilege must not break the page; the card shows "unknown".
        }

        return $info;
    }

    /**
     * Store today's (or $day's) per-table snapshot unless there is one. Returns whether it wrote.
     */
    public static function snapshot(?CarbonImmutable $day = null, bool $force = false): bool
    {
        $date = ($day ?? CarbonImmutable::now('UTC'))->utc()->toDateString();

        if (! $force && DB::table(self::SNAPSHOT_TABLE)->where('date', $date)->exists()) {
            return false;
        }

        $now = CarbonImmutable::now();
        $rows = array_map(fn (array $t): array => [
            'date' => $date,
            'table_name' => $t['name'],
            'row_count' => $t['rows'],
            'data_bytes' => $t['data_bytes'],
            'index_bytes' => $t['index_bytes'],
            'created_at' => $now,
            'updated_at' => $now,
        ], self::tables());

        $total = self::totalBytes();
        $rows[] = [
            'date' => $date,
            'table_name' => self::TOTAL,
            'row_count' => (int) array_sum(array_column($rows, 'row_count')),
            'data_bytes' => $total,
            'index_bytes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table(self::SNAPSHOT_TABLE)->upsert(
            $rows,
            ['date', 'table_name'],
            ['row_count', 'data_bytes', 'index_bytes', 'updated_at'],
        );

        return true;
    }

    /**
     * Today's tables with their growth since the latest snapshot on or before 7 and 30 days
     * ago: in bytes where sizes are known, else in rows. Null growth = no snapshot that old.
     *
     * @return list<array{name: string, rows: int, data_bytes: int|null, index_bytes: int|null, total_bytes: int|null, growth_7: int|null, growth_30: int|null, growth_unit: string}>
     */
    public static function tablesWithGrowth(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $baselines = [7 => self::baseline($today->subDays(7)), 30 => self::baseline($today->subDays(30))];

        return array_map(function (array $t) use ($baselines): array {
            $bySize = $t['total_bytes'] !== null;
            $growth = [];

            foreach ($baselines as $days => $baseline) {
                $then = $baseline[$t['name']] ?? null;
                $growth[$days] = match (true) {
                    $then === null => null,
                    $bySize && $then['bytes'] !== null => $t['total_bytes'] - $then['bytes'],
                    $bySize => null,
                    default => $t['rows'] - $then['rows'],
                };
            }

            return $t + ['growth_7' => $growth[7], 'growth_30' => $growth[30], 'growth_unit' => $bySize ? 'bytes' : 'rows'];
        }, self::tables());
    }

    /**
     * The latest snapshot on or before $day, per table.
     *
     * @return array<string, array{rows: int, bytes: int|null}>
     */
    private static function baseline(CarbonImmutable $day): array
    {
        $date = DB::table(self::SNAPSHOT_TABLE)->where('date', '<=', $day->toDateString())->max('date');

        if ($date === null) {
            return [];
        }

        return DB::table(self::SNAPSHOT_TABLE)->where('date', $date)->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->table_name => [
                'rows' => (int) $row->row_count,
                'bytes' => $row->data_bytes === null ? null : (int) $row->data_bytes + (int) $row->index_bytes,
            ]])->all();
    }

    /**
     * The whole database's size per snapshot day over the last $days days (days without a
     * snapshot are left out, today included once it has one).
     *
     * @return array<string, int>
     */
    public static function totalSeries(int $days): array
    {
        $from = CarbonImmutable::now('UTC')->startOfDay()->subDays($days - 1)->toDateString();

        return DB::table(self::SNAPSHOT_TABLE)
            ->where('table_name', self::TOTAL)
            ->where('date', '>=', $from)
            ->whereNotNull('data_bytes')
            ->orderBy('date')
            ->get(['date', 'data_bytes'])
            ->mapWithKeys(fn (object $row): array => [substr((string) $row->date, 0, 10) => (int) $row->data_bytes])
            ->all();
    }
}
