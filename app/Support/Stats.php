<?php

namespace App\Support;

use App\Models\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Totals over counted jobs ({@see Delivery::scopeCounted()}): every page and leaderboard uses
 * these, so the numbers can't drift apart.
 */
final class Stats
{
    /**
     * @param  Builder<Delivery>  $deliveries
     * @return array{distance_km: float, deliveries: int, income: int}
     */
    public static function totals(Builder $deliveries, Period $period = Period::All): array
    {
        return self::totalsBetween($deliveries, $period->start());
    }

    /**
     * Totals for jobs completed in [$from, $until) (either end open when null).
     *
     * @param  Builder<Delivery>  $deliveries
     * @return array{distance_km: float, deliveries: int, income: int}
     */
    public static function totalsBetween(Builder $deliveries, ?CarbonImmutable $from, ?CarbonImmutable $until = null): array
    {
        $query = $deliveries->clone()->counted();

        if ($from) {
            $query->where('deliveries.completed_at', '>=', $from);
        }

        if ($until) {
            $query->where('deliveries.completed_at', '<', $until);
        }

        $row = $query->toBase()
            ->selectRaw('COUNT(*) as deliveries, COALESCE(SUM(deliveries.distance_km), 0) as distance_km, COALESCE(SUM('.Delivery::INCOME_SQL.'), 0) as income')
            ->first();

        return [
            'distance_km' => round((float) ($row->distance_km ?? 0), 1),
            'deliveries' => (int) ($row->deliveries ?? 0),
            'income' => (int) ($row->income ?? 0),
        ];
    }

    /**
     * SQL expression for a leaderboard metric.
     *
     * @return literal-string
     */
    public static function metricExpression(string $metric): string
    {
        return match ($metric) {
            'deliveries' => 'COUNT(*)',
            'income' => 'SUM('.Delivery::INCOME_SQL.')',
            default => 'SUM(deliveries.distance_km)',
        };
    }
}
