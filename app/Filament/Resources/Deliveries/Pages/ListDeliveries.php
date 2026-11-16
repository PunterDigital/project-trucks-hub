<?php

namespace App\Filament\Resources\Deliveries\Pages;

use App\Filament\Resources\Deliveries\DeliveryResource;
use App\Models\Delivery;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDeliveries extends ListRecords
{
    protected static string $resource = DeliveryResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            // #4743: flagged jobs wait here, hidden from everyone but their driver.
            'pending' => Tab::make('Pending review')
                ->badge(Delivery::query()->pendingReview()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('deliveries.id', Delivery::query()->pendingReview()->select('deliveries.id'))),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('review', Delivery::REVIEW_REJECTED)),
        ];
    }
}
