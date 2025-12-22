<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\ShopifyRow;
use App\Services\HeaderStore;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterSave(): void
    {
        $data = $this->form->getState();
        $extra = $data['extra_shopify_fields'] ?? [];

        if (empty($extra)) {
            return;
        }

        $headers = $this->record->import?->headers ?? [];
        $allowed = array_flip(HeaderStore::extraProductHeaders($headers));
        if (empty($allowed)) {
            return;
        }

        $row = ShopifyRow::where('import_id', $this->record->import_id)
            ->where('handle', $this->record->handle)
            ->where('row_type', 'product_primary')
            ->first();

        if (!$row) {
            return;
        }

        $data = $row->data ?? [];
        $formState = $this->form->getState();
        if (array_key_exists('google_shopping_age_group', $formState)) {
            $data[HeaderStore::GOOGLE_SHOPPING_AGE_GROUP] = $formState['google_shopping_age_group'] ?? '';
        }
        if (array_key_exists('target_gender', $formState)) {
            $data['Target gender (product.metafields.shopify.target-gender)'] = $formState['target_gender'] ?? '';
        }
        if (array_key_exists('cost_per_item', $formState)) {
            $data['Cost per item'] = $formState['cost_per_item'] ?? '';
        }
        foreach ($extra as $item) {
            $key = $item['key'] ?? null;
            if (!$key || !isset($allowed[$key])) {
                continue;
            }
            $data[$key] = $item['value'] ?? '';
        }

        $row->data = $data;
        $row->save();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
