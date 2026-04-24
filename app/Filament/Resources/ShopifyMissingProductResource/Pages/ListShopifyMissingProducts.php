<?php

namespace App\Filament\Resources\ShopifyMissingProductResource\Pages;

use App\Filament\Resources\ShopifyMissingProductResource;
use Filament\Resources\Pages\ListRecords;

class ListShopifyMissingProducts extends ListRecords
{
    protected static string $resource = ShopifyMissingProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
