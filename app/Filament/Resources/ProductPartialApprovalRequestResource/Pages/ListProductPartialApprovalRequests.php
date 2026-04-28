<?php

namespace App\Filament\Resources\ProductPartialApprovalRequestResource\Pages;

use App\Filament\Resources\ProductPartialApprovalRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListProductPartialApprovalRequests extends ListRecords
{
    protected static string $resource = ProductPartialApprovalRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
