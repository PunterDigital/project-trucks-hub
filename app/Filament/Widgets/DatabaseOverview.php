<?php

namespace App\Filament\Widgets;

use App\Admin\DatabaseStats;
use Carbon\CarbonInterval;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/** Size, tables and the database server (#4666). No host or credentials. */
class DatabaseOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $total = DatabaseStats::totalBytes();
        $tables = DatabaseStats::tables();
        $conn = DatabaseStats::connection();

        return [
            Stat::make('Total size', $total === null ? 'Unknown' : Number::fileSize($total, 1)),
            Stat::make('Tables', Number::format(count($tables)))
                ->description(Number::format(array_sum(array_column($tables, 'rows'))).' rows'.(DatabaseStats::isMysql() ? ' (estimate)' : '')),
            Stat::make('Server', match ($conn['driver']) {
                'sqlite' => 'SQLite',
                'mysql' => 'MySQL',
                'mariadb' => 'MariaDB',
                'pgsql' => 'PostgreSQL',
                default => $conn['driver'],
            })
                ->description($conn['version'] ?? 'Version unknown'),
            Stat::make('Uptime', $conn['uptime_seconds'] === null ? '—' : CarbonInterval::seconds($conn['uptime_seconds'])->cascade()->forHumans(['parts' => 2, 'short' => true]))
                ->description($conn['connections'] === null ? 'Connections: —' : $conn['connections'].' open '.($conn['connections'] === 1 ? 'connection' : 'connections')),
        ];
    }
}
