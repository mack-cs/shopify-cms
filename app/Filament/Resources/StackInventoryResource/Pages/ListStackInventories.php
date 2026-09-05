<?php

namespace App\Filament\Resources\StackInventoryResource\Pages;

use App\Filament\Resources\StackInventoryResource;
use Filament\Resources\Pages\ListRecords;

final class ListStackInventories extends ListRecords
{
    protected static string $resource = StackInventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'See which component is making a stack unavailable. Quantities are from the latest Shopify inventory sync.';
    }
}
