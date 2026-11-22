<?php

namespace App\Support;

use App\Enums\DeliveryStatus;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Delivery;
use Carbon\CarbonImmutable;

/**
 * Member stats download (#5226) for a company's owners and managers: one row per current
 * member (suspended ones left out) with the company's counted jobs they drove in the period,
 * a UTC month or all time. Income uses {@see Delivery::INCOME_SQL}, as every other total.
 */
final class CompanyMemberStats
{
    public const ALL = 'all';

    public const COLUMNS = ['Driver', 'Username', 'Role', 'Jobs', 'Distance (km)', 'Income (EUR)', 'On time (%)', 'Last job (UTC)'];

    /** Months offered besides all time: this one and the 11 before it. */
    public const MONTHS = 12;

    /**
     * The periods the page offers, newest month first, then all time.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function periods(?CarbonImmutable $now = null): array
    {
        $month = ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfMonth();
        $periods = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $m = $month->subMonthsNoOverflow($i);
            $periods[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }
        $periods[] = ['value' => self::ALL, 'label' => __('All time')];

        return $periods;
    }

    public static function isPeriod(mixed $period, ?CarbonImmutable $now = null): bool
    {
        return is_string($period) && in_array($period, array_column(self::periods($now), 'value'), true);
    }

    /** @return list<list<string|int|float>> */
    public static function rows(Company $company, string $period): array
    {
        $query = $company->deliveries()->counted()->toBase();
        if ($period !== self::ALL) {
            $start = CompanyAwards::monthStart($period);
            $query->where('deliveries.completed_at', '>=', $start)
                ->where('deliveries.completed_at', '<', $start->addMonthNoOverflow());
        }

        $delivered = DeliveryStatus::Delivered->value;
        $stats = $query
            ->selectRaw('deliveries.user_id, COUNT(*) as jobs, COALESCE(SUM(deliveries.distance_km), 0) as distance_km')
            ->selectRaw('COALESCE(SUM('.Delivery::INCOME_SQL.'), 0) as income')
            ->selectRaw('SUM(CASE WHEN deliveries.status = ? THEN 1 ELSE 0 END) as on_time', [$delivered])
            ->selectRaw('MAX(deliveries.completed_at) as last_job')
            ->groupBy('deliveries.user_id')
            ->get()
            ->keyBy('user_id');

        return array_values($company->members()->with('user')->get()
            ->reject(fn (CompanyMember $m) => $m->user->isSuspended())
            ->sortBy(fn (CompanyMember $m) => mb_strtolower($m->user->name))
            ->map(function (CompanyMember $m) use ($stats) {
                $s = $stats->get($m->user_id);
                $jobs = (int) ($s->jobs ?? 0);

                return [
                    $m->user->name,
                    $m->user->username ?? '',
                    $m->role->value,
                    $jobs,
                    round((float) ($s->distance_km ?? 0), 1),
                    (int) ($s->income ?? 0),
                    $jobs > 0 ? round(100 * (int) $s->on_time / $jobs, 1) : '',
                    isset($s->last_job) ? CarbonImmutable::parse((string) $s->last_job, 'UTC')->format('Y-m-d H:i') : '',
                ];
            })
            ->all());
    }

    /** RFC 4180 CSV with a header row. */
    public static function csv(Company $company, string $period): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream.');
        }

        foreach ([self::COLUMNS, ...self::rows($company, $period)] as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public static function filename(Company $company, string $period): string
    {
        return $company->slug.'-member-stats-'.$period.'.csv';
    }

    /** Spreadsheets run a cell that starts with = + - @ as a formula: driver names come from drivers. */
    private static function cell(string|int|float $value): string
    {
        $text = (string) $value;

        return is_string($value) && $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }
}
