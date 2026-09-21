<?php

namespace App\Filament\Resources\ProductInventorySnapshotResource\Pages;

use App\Filament\Resources\ProductInventorySnapshotResource;
use App\Filament\Resources\ProductInventorySnapshotResource\Widgets\InventoryAvailabilityTrendChart;
use Filament\Resources\Pages\ListRecords;

class ListProductInventorySnapshots extends ListRecords
{
    protected static string $resource = ProductInventorySnapshotResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryAvailabilityTrendChart::class,
        ];
    }
}
