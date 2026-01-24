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
            $value = trim((string) ($formState['google_shopping_age_group'] ?? ''));
            $data[HeaderStore::GOOGLE_SHOPPING_AGE_GROUP] = $value === '' ? '' : strtolower($value);
        }
        if (array_key_exists('target_gender', $formState)) {
            $value = trim((string) ($formState['target_gender'] ?? ''));
            $data[HeaderStore::TARGET_GENDER] = $value === '' ? '' : strtolower($value);
        }
        if (array_key_exists('age_group', $formState)) {
            $value = trim((string) ($formState['age_group'] ?? ''));
            $data[HeaderStore::AGE_GROUP] = $value === '' ? '' : strtolower($value);
        }
        if (array_key_exists('materials_and_dimensions', $formState)) {
            $data[HeaderStore::MATERIALS_AND_DIMENSIONS] = $formState['materials_and_dimensions'] ?? '';
        }
        if (array_key_exists('jewelry_material', $formState)) {
            $data[HeaderStore::JEWELRY_MATERIAL] = $formState['jewelry_material'] ?? '';
        }
        if (array_key_exists('jewelry_type', $formState)) {
            $data[HeaderStore::JEWELRY_TYPE] = $formState['jewelry_type'] ?? '';
        }
        if (array_key_exists('bracelet_design', $formState)) {
            $data[HeaderStore::BRACELET_DESIGN] = $formState['bracelet_design'] ?? '';
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

        if (array_key_exists('variant_weight_unit', $formState)) {
            $normalized = trim((string) ($formState['variant_weight_unit'] ?? ''));
            $this->record->variants()->update([
                'weight_unit' => $normalized === '' ? null : $normalized,
            ]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ProductResource::canDelete($this->getRecord())),
        ];
    }
}
