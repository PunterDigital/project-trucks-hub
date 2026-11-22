<?php

namespace App\Support;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyAward;
use App\Models\Delivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Driver of the month (#5226). After each UTC month every company's top three drivers by
 * counted distance driven for it ({@see Delivery::scopeCounted()}, `deliveries.company_id` =
 * the company, completed in the month) get an award: 1st, 2nd, 3rd. A tie goes to whoever made
 * their first counted delivery of the month earlier. Drivers who have left since still get
 * theirs: the jobs were for the company. A company that already has awards for the month is
 * skipped, so a rerun changes nothing, even after late syncs.
 *
 * @phpstan-type Winner array{place: int, distance_km: float, driver: array{name: string, username: string|null, avatar: string|null}}
 * @phpstan-type MonthAwards array{month: string, label: string, winners: list<Winner>}
 * @phpstan-type DriverAward array{month: string, label: string, place: int, company: array{name: string, slug: string, tag: string}}
 */
final class CompanyAwards
{
    public const PLACES = 3;

    /** Months shown on the company page and the driver's profile. */
    public const MONTHS_SHOWN = 12;

    /**
     * Awards every company for `$month` ("YYYY-MM", a finished UTC month). Returns how many
     * companies got awards this time.
     */
    public static function award(string $month, ?CarbonImmutable $now = null): int
    {
        $start = self::monthStart($month);
        $now ??= CarbonImmutable::now('UTC');
        if ($start->addMonthNoOverflow()->greaterThan($now->utc())) {
            throw new InvalidArgumentException("{$month} hasn't finished yet.");
        }

        $done = CompanyAward::query()->where('month', $month)->distinct()->pluck('company_id')->all();

        $rows = Delivery::query()->counted()->toBase()
            ->whereNotNull('deliveries.company_id')
            ->whereNotIn('deliveries.company_id', $done)
            ->whereIn('deliveries.company_id', Company::query()->select('id'))
            ->where('deliveries.completed_at', '>=', $start)
            ->where('deliveries.completed_at', '<', $start->addMonthNoOverflow())
            ->selectRaw('deliveries.company_id, deliveries.user_id, SUM(deliveries.distance_km) as distance_km, MIN(deliveries.completed_at) as first_at')
            ->groupBy('deliveries.company_id', 'deliveries.user_id')
            ->get()
            ->groupBy('company_id');

        $awarded = 0;
        $stamp = $now->utc();
        foreach ($rows as $companyId => $drivers) {
            $top = $drivers
                ->sort(fn (object $a, object $b) => [(float) $b->distance_km, (string) $a->first_at, (int) $a->user_id]
                    <=> [(float) $a->distance_km, (string) $b->first_at, (int) $b->user_id])
                ->filter(fn (object $r) => (float) $r->distance_km > 0)
                ->take(self::PLACES)
                ->values();

            if ($top->isEmpty()) {
                continue;
            }

            $inserted = CompanyAward::query()->insertOrIgnore($top->map(fn (object $r, int $i) => [
                'company_id' => (int) $companyId,
                'user_id' => (int) $r->user_id,
                'month' => $month,
                'place' => $i + 1,
                'distance_km' => round((float) $r->distance_km, 1),
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ])->all());

            if ($inserted > 0) {
                $awarded++;
            }
        }

        return $awarded;
    }

    /** The month before `$now`'s, as "YYYY-MM". */
    public static function lastMonth(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * The company page's awards, newest month first. Private drivers are "Private driver" to
     * anyone outside the company, as on the members list; suspended drivers are left out.
     *
     * @return list<MonthAwards>
     */
    public static function forCompany(Company $company, ?User $viewer, ?CompanyRole $role, ?CarbonImmutable $now = null): array
    {
        $from = self::firstShownMonth($now);

        $months = [];
        CompanyAward::query()
            ->where('company_id', $company->id)
            ->where('month', '>=', $from)
            ->with('user')
            ->orderByDesc('month')
            ->orderBy('place')
            ->get()
            ->reject(fn (CompanyAward $a) => $a->user->isSuspended())
            ->each(function (CompanyAward $a) use (&$months, $viewer, $role) {
                $visible = $role !== null || $a->user->isVisibleTo($viewer);
                $months[$a->month] ??= ['month' => $a->month, 'label' => self::label($a->month), 'winners' => []];
                $months[$a->month]['winners'][] = [
                    'place' => $a->place,
                    'distance_km' => $a->distance_km,
                    'driver' => $visible
                        ? ['name' => $a->user->name, 'username' => $a->user->username, 'avatar' => $a->user->avatar()]
                        : ['name' => __('Private driver'), 'username' => null, 'avatar' => null],
                ];
            });

        return array_values($months);
    }

    /**
     * A driver's awards for their profile, newest first.
     *
     * @return list<DriverAward>
     */
    public static function forDriver(User $driver, ?CarbonImmutable $now = null): array
    {
        return array_values(CompanyAward::query()
            ->where('user_id', $driver->id)
            ->where('month', '>=', self::firstShownMonth($now))
            ->with('company')
            ->orderByDesc('month')
            ->orderBy('place')
            ->get()
            ->map(fn (CompanyAward $a) => [
                'month' => $a->month,
                'label' => self::label($a->month),
                'place' => $a->place,
                'company' => ['name' => $a->company->name, 'slug' => $a->company->slug, 'tag' => $a->company->tag],
            ])
            ->all());
    }

    public static function label(string $month): string
    {
        return self::monthStart($month)->format('F Y');
    }

    public static function monthStart(string $month): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException("Not a month: {$month}");
        }

        return CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC') ?: throw new InvalidArgumentException("Not a month: {$month}");
    }

    private static function firstShownMonth(?CarbonImmutable $now): string
    {
        return ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfMonth()->subMonthsNoOverflow(self::MONTHS_SHOWN)->format('Y-m');
    }
}
