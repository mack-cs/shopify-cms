<?php

namespace App\Filament\Resources\ShopifyOrderResource\Pages;

use App\Filament\Resources\ShopifyOrderResource;
use App\Services\Shopify\ShopifyAnalyticsExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;

class ListShopifyOrders extends ListRecords
{
    protected static string $resource = ShopifyOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('paymentPlatformReport')
                ->label('Payment platform CSV')
                ->icon('heroicon-o-banknotes')
                ->form([
                    DatePicker::make('from')->required()->default(now()->startOfMonth()->toDateString()),
                    DatePicker::make('to')->required()->default(now()->toDateString())->afterOrEqual('from'),
                ])
                ->action(fn (array $data) => app(ShopifyAnalyticsExportService::class)
                    ->paymentPlatformCsv((string) $data['from'], (string) $data['to'])),
            Action::make('mlOrderLines')
                ->label('ML order lines CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->form([
                    DatePicker::make('from')->required()->default('2023-01-01'),
                    DatePicker::make('to')->required()->default(now()->toDateString())->afterOrEqual('from'),
                ])
                ->action(fn (array $data) => app(ShopifyAnalyticsExportService::class)
                    ->mlOrderLinesCsv((string) $data['from'], (string) $data['to'])),
            Action::make('mlProducts')
                ->label('ML products CSV')
                ->icon('heroicon-o-cube')
                ->action(fn () => app(ShopifyAnalyticsExportService::class)->mlProductsCsv()),
            Action::make('stackComponents')
                ->label('Stack components CSV')
                ->icon('heroicon-o-link')
                ->action(fn () => app(ShopifyAnalyticsExportService::class)->stackComponentsCsv()),
        ];
    }
}
