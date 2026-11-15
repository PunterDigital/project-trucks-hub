<?php

namespace App\Support;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Chart data for driver profiles and company pages (#4650), over counted jobs only.
 *
 * Distance comes as sparse 15-minute UTC buckets, `[unix seconds, km]`, and the page folds
 * them into the viewer's own days: every real time zone is a whole number of quarter hours
 * from UTC, so each bucket lands on exactly one local day, DST included, and the server
 * never has to know the viewer's zone. The window covers the last 30 days in any zone.
 */
final class Charts
{
    public const DAYS = 30;

    public const BUCKET_SECONDS = 900;

    /** Top members in a company chart; the rest are summed as "Others". */
    public const TOP_MEMBERS = 5;

    public const TOP_CARGO = 8;

    /**
     * @param  Builder<Delivery>  $deliveries
     * @return list<array{0: int, 1: float}>
     */
    public static function distance(Builder $deliveries): array
    {
        return self::buckets(self::windowRows($deliveries));
    }

    /**
     * Distance per member, the top {@see TOP_MEMBERS} by distance in the window and the
     * rest as "Others". Private drivers are named only to people who could see them on the
     * members list (company mates, or anyone their profile is public to).
     *
     * @return list<array{key: string, name: string, username: string|null, distance_km: float, points: list<array{0: int, 1: float}>}>
     */
    public static function companyDistance(Company $company, ?User $viewer, ?CompanyRole $role): array
    {
        $rows = self::windowRows($company->deliveries()->getQuery());

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row['user_id']][] = $row;
        }

        $totals = array_map(fn (array $r) => array_sum(array_column($r, 'km')), $byUser);
        arsort($totals);

        $topIds = array_slice(array_keys($totals), 0, self::TOP_MEMBERS);
        $users = User::query()->whereKey($topIds)->get()->keyBy('id');

        $series = [];
        foreach ($topIds as $id) {
            $user = $users->get($id);
            $visible = $user !== null && ($role !== null || $user->isVisibleTo($viewer));
            $series[] = [
                'key' => 'driver-'.$id,
                'name' => $visible ? $user->name : __('Private driver'),
                'username' => $visible ? $user->username : null,
                'distance_km' => round($totals[$id], 1),
                'points' => self::buckets($byUser[$id]),
            ];
        }

        $others = [];
        foreach (array_slice(array_keys($totals), self::TOP_MEMBERS) as $id) {
            array_push($others, ...$byUser[$id]);
        }

        if ($others !== []) {
            $series[] = [
                'key' => 'others',
                'name' => __('Others'),
                'username' => null,
                'distance_km' => round(array_sum(array_column($others, 'km')), 1),
                'points' => self::buckets($others),
            ];
        }

        return $series;
    }

    /**
     * Counted jobs by cargo, most first.
     *
     * @param  Builder<Delivery>  $deliveries
     * @return list<array{name: string, deliveries: int, distance_km: float}>
     */
    public static function cargo(Builder $deliveries): array
    {
        return array_values($deliveries->clone()->counted()->toBase()
            ->selectRaw('deliveries.cargo_name as name, COUNT(*) as deliveries, COALESCE(SUM(deliveries.distance_km), 0) as distance_km')
            ->whereNotNull('deliveries.cargo_name')
            ->where('deliveries.cargo_name', '!=', '')
            ->groupBy('deliveries.cargo_name')
            ->orderByDesc('deliveries')
            ->orderByDesc('distance_km')
            ->orderBy('deliveries.cargo_name')
            ->limit(self::TOP_CARGO)
            ->get()
            ->map(fn ($c) => [
                'name' => (string) $c->name,
                'deliveries' => (int) $c->deliveries,
                'distance_km' => round((float) $c->distance_km, 1),
            ])
            ->all());
    }

    /**
     * Counted jobs that may fall inside the last {@see DAYS} days somewhere on Earth
     * (UTC+14 to UTC−12), so one more day on each side.
     *
     * @param  Builder<Delivery>  $deliveries
     * @return list<array{ts: int, km: float, user_id: int}>
     */
    private static function windowRows(Builder $deliveries): array
    {
        return array_values($deliveries->clone()->counted()->toBase()
            ->select(['deliveries.completed_at', 'deliveries.distance_km', 'deliveries.user_id'])
            ->where('deliveries.completed_at', '>=', now()->subDays(self::DAYS + 1)->startOfDay())
            ->where('deliveries.completed_at', '<=', now()->addDay())
            ->get()
            ->map(fn ($r) => [
                'ts' => Carbon::parse($r->completed_at, 'UTC')->getTimestamp(),
                'km' => (float) $r->distance_km,
                'user_id' => (int) $r->user_id,
            ])
            ->all());
    }

    /**
     * @param  list<array{ts: int, km: float, user_id: int}>  $rows
     * @return list<array{0: int, 1: float}>
     */
    private static function buckets(array $rows): array
    {
        $sums = [];
        foreach ($rows as $row) {
            $bucket = intdiv($row['ts'], self::BUCKET_SECONDS) * self::BUCKET_SECONDS;
            $sums[$bucket] = ($sums[$bucket] ?? 0.0) + $row['km'];
        }
        ksort($sums);

        $points = [];
        foreach ($sums as $ts => $km) {
            $points[] = [$ts, round($km, 1)];
        }

        return $points;
    }
}
