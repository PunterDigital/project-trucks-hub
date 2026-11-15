<?php

namespace App\Support;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\LiveStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** The lists several pages share: latest deliveries and who is on the road. */
final class Feed
{
    /**
     * @param  (callable(Builder<Delivery>): mixed)|null  $scope
     * @return list<array<string, mixed>>
     */
    public static function latestDeliveries(?User $viewer, int $limit = 15, ?callable $scope = null): array
    {
        $query = Delivery::query()
            ->visibleTo($viewer)
            ->where('deliveries.hidden', false)
            ->whereIn('deliveries.status', DeliveryStatus::counted())
            ->with('user.membership.company')
            ->withReactions($viewer)
            ->latest('completed_at')
            ->limit($limit);

        if ($scope !== null) {
            $scope($query);
        }

        return array_values($query->get()->map(fn (Delivery $d) => $d->summary())->all());
    }

    /**
     * @param  (callable(Builder<LiveStatus>): mixed)|null  $scope
     * @return list<array<string, mixed>>
     */
    public static function onTheRoad(?User $viewer, int $limit = 10, ?callable $scope = null): array
    {
        $query = LiveStatus::query()
            ->fresh()
            ->whereHas('user', fn (Builder $u) => $u->visibleTo($viewer))
            ->with('user.membership.company')
            ->latest('updated_at')
            ->limit($limit);

        if ($scope !== null) {
            $scope($query);
        }

        return array_values($query->get()->map(fn (LiveStatus $s) => $s->summary())->all());
    }
}
