<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open')
                ->badge(Report::query()->open()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Report::OPEN)),
            'resolved' => Tab::make('Resolved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Report::RESOLVED)),
            'all' => Tab::make('All'),
        ];
    }
}
