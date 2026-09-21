<?php

namespace App\Filament\Resources\ShopifyInventorySnapshotResource\Pages;

use App\Filament\Resources\ShopifyInventorySnapshotResource;
use App\Services\Shopify\ShopifyAnalyticsExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;

class ListShopifyInventorySnapshots extends ListRecords
{
    protected static string $resource = ShopifyInventorySnapshotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saleInventoryReport')
                ->label('Sale inventory CSV')
                ->icon('heroicon-o-presentation-chart-line')
                ->form([
                    DatePicker::make('from')->required()->default(now()->startOfMonth()->toDateString()),
                    DatePicker::make('to')->required()->default(now()->toDateString())->afterOrEqual('from'),
                ])
                ->action(fn (array $data) => app(ShopifyAnalyticsExportService::class)
                    ->saleInventoryCsv((string) $data['from'], (string) $data['to'])),
            Action::make('inventoryEvents')
                ->label('ML inventory events CSV')
                ->icon('heroicon-o-clock')
                ->form([
                    DatePicker::make('from')->required()->default('2023-01-01'),
                    DatePicker::make('to')->required()->default(now()->toDateString())->afterOrEqual('from'),
                ])
                ->action(fn (array $data) => app(ShopifyAnalyticsExportService::class)
                    ->inventoryEventsCsv((string) $data['from'], (string) $data['to'])),
        ];
    }
}
