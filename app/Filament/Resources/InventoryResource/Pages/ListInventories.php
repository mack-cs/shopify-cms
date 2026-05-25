<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use App\Models\Variant;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $counts = $this->tabCounts();

        return [
            'all' => Tab::make('All')
                ->badge((string) $counts['all']),
            'in_stock' => Tab::make('In Stock')
                ->badge((string) $counts['in_stock'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->whereRaw('LOWER(status) = ?', ['active']))
                    ->where('inventory_tracked', true)
                    ->where('inventory_qty', '>', 0)),
            'out_of_stock' => Tab::make('Out Of Stock')
                ->badge((string) $counts['out_of_stock'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('inventory_tracked', true)
                    ->where('inventory_qty', '<=', 0)),
            'not_tracked' => Tab::make('Not Tracked')
                ->badge((string) $counts['not_tracked'])
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(function (Builder $builder): void {
                    $builder->where('inventory_tracked', false)
                        ->orWhereNull('inventory_tracked');
                })),
        ];
    }

    /**
     * @return array{all:int,in_stock:int,out_of_stock:int,not_tracked:int}
     */
    private function tabCounts(): array
    {
        $baseQuery = Variant::query()->whereHas('product');

        return [
            'all' => (clone $baseQuery)->count(),
            'in_stock' => (clone $baseQuery)
                ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery->whereRaw('LOWER(status) = ?', ['active']))
                ->where('inventory_tracked', true)
                ->where('inventory_qty', '>', 0)
                ->count(),
            'out_of_stock' => (clone $baseQuery)
                ->where('inventory_tracked', true)
                ->where('inventory_qty', '<=', 0)
                ->count(),
            'not_tracked' => (clone $baseQuery)
                ->where(function (Builder $builder): void {
                    $builder->where('inventory_tracked', false)
                        ->orWhereNull('inventory_tracked');
                })
                ->count(),
        ];
    }
}
