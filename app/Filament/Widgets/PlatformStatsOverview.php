<?php

namespace App\Filament\Widgets;

use App\Admin\DailyStats;
use App\Filament\Pages\Dashboard;
use App\Models\LiveStatus;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/** The dashboard's stat cards, each with the change against the period before (#4665). */
class PlatformStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 5;
    }

    protected function getHeading(): string
    {
        $days = Dashboard::days($this->pageFilters);

        return "Last {$days} days, compared with the {$days} days before";
    }

    protected function getStats(): array
    {
        $days = Dashboard::days($this->pageFilters);
        $periods = DailyStats::periods($days);

        $stat = function (string $label, string $metric, ?callable $format = null) use ($periods): Stat {
            $current = $periods['current'][$metric];
            $previous = $periods['previous'][$metric];
            [$text, $color, $icon] = self::change($current, $previous);

            return Stat::make($label, $format ? $format($current) : Number::format($current))
                ->description($text)
                ->descriptionIcon($icon)
                ->color($color)
                ->chart($periods['daily'][$metric]);
        };

        $linked = (int) DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->distinct()
            ->count('tokenable_id');
        $newLinks = (int) $periods['current']['links_approved'];

        return [
            $stat('Email sign-ups', 'signups_email'),
            $stat('Steam sign-ups', 'signups_steam'),
            Stat::make('Drivers with a linked app', Number::format($linked))
                ->description($newLinks.' new '.($newLinks === 1 ? 'link' : 'links').' in the period')
                ->descriptionIcon(Heroicon::OutlinedLink),
            $stat('Jobs synced', 'jobs_synced'),
            $stat('Km synced', 'km_synced', fn (float $km): string => Number::format($km, 0).' km'),
            $stat('Active drivers', 'active_drivers'),
            $stat('Companies created', 'companies_created'),
            $stat('Applications sent', 'applications_sent'),
            $stat('Applications accepted', 'applications_accepted'),
            Stat::make('On the road now', Number::format(LiveStatus::query()->fresh()->count()))
                ->description('Reporting live')
                ->descriptionIcon(Heroicon::OutlinedTruck),
        ];
    }

    /**
     * The change line under a card: text, colour and icon.
     *
     * @return array{string, string, Heroicon}
     */
    public static function change(float $current, float $previous): array
    {
        if ($current == $previous) {
            return ['No change', 'gray', Heroicon::OutlinedMinus];
        }

        if ($previous == 0) {
            return ['Up from 0', 'success', Heroicon::OutlinedArrowTrendingUp];
        }

        $pct = (int) round(abs($current - $previous) / $previous * 100);

        return $current > $previous
            ? ["Up {$pct}%", 'success', Heroicon::OutlinedArrowTrendingUp]
            : ["Down {$pct}%", 'danger', Heroicon::OutlinedArrowTrendingDown];
    }
}
