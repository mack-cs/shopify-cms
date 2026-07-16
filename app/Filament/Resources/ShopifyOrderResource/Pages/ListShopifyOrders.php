<?php

namespace App\Filament\Resources\ShopifyOrderResource\Pages;

use App\Filament\Resources\ShopifyOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListShopifyOrders extends ListRecords
{
    protected static string $resource = ShopifyOrderResource::class;
}
