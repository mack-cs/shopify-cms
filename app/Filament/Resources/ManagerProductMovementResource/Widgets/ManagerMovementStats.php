<?php

namespace App\Filament\Resources\ManagerProductMovementResource\Widgets;

use App\Filament\Resources\ManagerProductMovementResource\Pages\ListManagerProductMovements;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

final class ManagerMovementStats extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListManagerProductMovements::class;
    }

    protected function getStats(): array
    {
        $query = $this->getPageTableQuery();
        $total = $this->uniqueProductCount(clone $query);
        $fast = $this->uniqueProductCount((clone $query)->where('movement_classification', 'fast_moving'));
        $slow = $this->uniqueProductCount((clone $query)->where('movement_classification', 'slow_moving'));
        $noSales = $this->uniqueProductCount((clone $query)->where('movement_classification', 'no_sales'));
        $restock = $this->uniqueProductCount((clone $query)->where('recommended_action', 'restock'));
        $review = $this->uniqueProductCount(
            (clone $query)->whereIn('recommended_action', ['review', 'insufficient_data'])
        );

        return [
            Stat::make('Products analysed', number_format($total))->color('gray'),
            Stat::make('Fast moving', number_format($fast))->color('success'),
            Stat::make('Slow moving', number_format($slow))->color('warning'),
            Stat::make('No sales', number_format($noSales))->color('danger'),
            Stat::make('Need restocking', number_format($restock))->color('success'),
            Stat::make('Need review', number_format($review))->color('danger'),
        ];
    }

    private function uniqueProductCount(Builder $query): int
    {
        return (int) $query->reorder()->distinct()->count('product_id');
    }
}
