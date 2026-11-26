<?php

namespace App\Filament\Resources\NewInExpirationOperationResource\Pages;

use App\Filament\Resources\NewInExpirationOperationResource;
use Filament\Resources\Pages\ListRecords;

final class ListNewInExpirationOperations extends ListRecords
{
    protected static string $resource = NewInExpirationOperationResource::class;

    protected static ?string $title = 'New In Removals';

    public function getSubheading(): ?string
    {
        return 'Times are shown in South African Standard Time. If a removal was confirmed on a later Shopify check, the report shows that confirmation time.';
    }
}
