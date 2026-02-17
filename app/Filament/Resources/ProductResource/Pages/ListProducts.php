<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\ProductStatusStats;
use App\Filament\Resources\ProductResource\Widgets\PendingProductSyncBanner;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PendingProductSyncBanner::class,
            ProductStatusStats::class,
        ];
    }

    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('All'),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', ['active'])),
            'draft' => Tab::make('Draft')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', ['draft'])),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', ['archived'])),
        ];

        $extraStatuses = Product::query()
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->selectRaw('LOWER(status) as status_key, status as status_label')
            ->distinct()
            ->get()
            ->filter(fn ($row) => !in_array($row->status_key, ['active', 'draft', 'archived'], true));

        foreach ($extraStatuses as $row) {
            $label = $row->status_label;
            $key = $row->status_key;
            $tabs[$key] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', [$key]));
        }

        return $tabs;
    }
}
