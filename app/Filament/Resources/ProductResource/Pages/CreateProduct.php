<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Import;
use App\Models\ShopifyRow;
use App\Services\HeaderStore;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use League\Csv\Reader;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $currentImportId = Import::where('is_current', true)->value('id');
        if ($currentImportId) {
            $data['import_id'] = $currentImportId;
        }

        unset($data['extra_shopify_fields'], $data['google_shopping_age_group'], $data['target_gender'], $data['cost_per_item']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        if (!$record) {
            return;
        }

        $headers = $record->import?->headers ?? [];
        if (empty($headers)) {
            $headers = $this->templateHeaders();
        }

        if (empty($headers)) {
            return;
        }

        $rowIndex = (int) (ShopifyRow::where('import_id', $record->import_id)->max('row_index') ?? 0);
        $rowIndex++;

        $data = array_fill_keys($headers, '');
        if (array_key_exists(HeaderStore::HANDLE, $data)) {
            $data[HeaderStore::HANDLE] = $record->handle;
        }

        $formState = $this->form->getState();
        $extra = $formState['extra_shopify_fields'] ?? [];
        foreach ($extra as $item) {
            $key = $item['key'] ?? null;
            if (!$key || !array_key_exists($key, $data)) {
                continue;
            }
            $data[$key] = $item['value'] ?? '';
        }

        if (array_key_exists('google_shopping_age_group', $formState)) {
            $data[HeaderStore::GOOGLE_SHOPPING_AGE_GROUP] = $formState['google_shopping_age_group'] ?? '';
        }
        if (array_key_exists('target_gender', $formState)) {
            $data['Target gender (product.metafields.shopify.target-gender)'] = $formState['target_gender'] ?? '';
        }
        if (array_key_exists('cost_per_item', $formState)) {
            $data['Cost per item'] = $formState['cost_per_item'] ?? '';
        }

        ShopifyRow::create([
            'import_id' => $record->import_id,
            'row_index' => $rowIndex,
            'handle' => $record->handle,
            'row_type' => 'product_primary',
            'variant_key' => null,
            'image_key' => null,
            'data' => $data,
        ]);
    }

    private function templateHeaders(): array
    {
        $templatePath = storage_path('app/private/imports/products.csv');
        if (!is_file($templatePath)) {
            return [];
        }

        $csv = Reader::createFromPath($templatePath);
        $csv->setHeaderOffset(0);
        return $csv->getHeader();
    }
}
