<?php

namespace App\Filament\Widgets;

use App\Admin\StorageStats;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/** The server disk with the low-space warning, uploads and the database (#4667). */
class StorageOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $disk = StorageStats::disk();
        $items = collect(StorageStats::items())->keyBy('key');
        $uploads = $items->only(StorageStats::UPLOADS);
        $database = $items->get('database')['bytes'] ?? null;
        $size = fn (?int $bytes): string => $bytes === null ? 'Unknown' : Number::fileSize($bytes, 1);

        $free = $disk['low']
            ? Stat::make('Free space', $size($disk['free']))
                ->description('Low: under 10% or 5 GB free')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
            : Stat::make('Free space', $size($disk['free']))
                ->description($disk['total'] === null || $disk['free'] === null ? 'Disk size unknown' : Number::percentage($disk['free'] / max(1, $disk['total']) * 100).' of the disk')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success');

        return [
            $free,
            Stat::make('Disk used', $size($disk['used']))
                ->description('of '.$size($disk['total']).' (the disk holding storage/)'),
            Stat::make('Uploads', Number::fileSize((int) $uploads->sum('bytes'), 1))
                ->description(Number::format((int) $uploads->sum('files')).' files'),
            Stat::make('Database', $size($database)),
        ];
    }
}
