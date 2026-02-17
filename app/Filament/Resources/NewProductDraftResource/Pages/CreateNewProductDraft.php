<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateNewProductDraft extends CreateRecord
{
    protected static string $resource = NewProductDraftResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();
        $data['variant_inventory_policy'] = $data['variant_inventory_policy'] ?? 'deny';
        $data['variant_fulfillment_service'] = $data['variant_fulfillment_service'] ?? 'manual';
        $data['status'] = $data['status'] ?? 'draft';
        $data['batch'] = $data['batch'] ?? ('batch' . now()->format('Ymd'));
        return $data;
    }
}
