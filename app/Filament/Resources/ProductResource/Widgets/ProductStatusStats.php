<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductStatusStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $total = Product::query()->count();
        $active = Product::query()->whereRaw('LOWER(status) = ?', ['active'])->count();
        $draft = Product::query()->whereRaw('LOWER(status) = ?', ['draft'])->count();
        $archived = Product::query()->whereRaw('LOWER(status) = ?', ['archived'])->count();

        return [
            Stat::make('Total products', number_format($total))
                ->color('gray')
                ->icon('heroicon-m-rectangle-stack'),
            Stat::make('Active', number_format($active))
                ->color('success')
                ->icon('heroicon-m-check-circle'),
            Stat::make('Draft', number_format($draft))
                ->color('warning')
                ->icon('heroicon-m-pencil-square'),
            Stat::make('Archived', number_format($archived))
                ->color('danger')
                ->icon('heroicon-m-archive-box'),
        ];
    }
}
