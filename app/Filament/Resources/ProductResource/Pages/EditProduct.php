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

    /**
     * Fields owned by New Product Draft workflow; Product edit page must not persist them.
     *
     * @return array<int, string>
     */
    private function draftOwnedFormKeys(): array
    {
        return [
            'title',
            'body_html',
            'vendor',
            'tags',
            'type',
            'published',
            'product_category',
            'google_product_category',
            'status',
            'color_string',
            'variant_price',
            'uvp_short_paragraph',
            'google_shopping_age_group',
            'target_gender',
            'age_group',
            'materials_and_dimensions',
            'jewelry_material',
            'jewelry_type',
            'bracelet_design',
            'necklace_design',
            'earring_design',
            'pattern_category',
            'product_metals',
            'cost_per_item',
            'batch',
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach ($this->draftOwnedFormKeys() as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $data = $this->form->getState();

        $rows = ShopifyRow::where('import_id', $this->record->import_id)
            ->where('handle', $this->record->handle)
            ->where('row_type', 'product_primary')
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) {
            $rowIndex = (int) (ShopifyRow::where('import_id', $this->record->import_id)->max('row_index') ?? 0);
            $row = ShopifyRow::create([
                'import_id' => $this->record->import_id,
                'row_index' => $rowIndex + 1,
                'handle' => $this->record->handle,
                'row_type' => 'product_primary',
                'variant_key' => null,
                'image_key' => null,
                'data' => [
                    HeaderStore::HANDLE => $this->record->handle,
                    HeaderStore::TITLE => $this->record->title,
                    HeaderStore::BODY_HTML => $this->record->body_html,
                    HeaderStore::VENDOR => $this->record->vendor,
                    HeaderStore::TAGS => $this->record->tags,
                    HeaderStore::TYPE => $this->record->type,
                    HeaderStore::STATUS => $this->record->status,
                    HeaderStore::PUBLISHED => $this->record->published,
                    HeaderStore::PRODUCT_CATEGORY => $this->record->product_category,
                    HeaderStore::GOOGLE_PRODUCT_CATEGORY => $this->record->google_product_category,
                ],
            ]);
            $rows = collect([$row]);
        }

        $data = ($rows->first()->data ?? []);
        $formState = $this->form->getState();
        foreach ($this->draftOwnedFormKeys() as $key) {
            unset($formState[$key]);
        }
        if (array_key_exists('color_string', $formState)) {
            $color = $formState['color_string'];
            if (is_array($color)) {
                $color = implode('; ', array_values(array_unique(array_filter(array_map(
                    fn ($value) => trim((string) $value),
                    $color
                )))));
            }
            $data[HeaderStore::COLOR_METAFIELD] = $color ?? '';
        }
        if (array_key_exists('variant_price', $formState)) {
            $data[HeaderStore::VARIANT_PRICE] = $formState['variant_price'] ?? '';
        }
        if (array_key_exists('uvp_short_paragraph', $formState)) {
            $data[HeaderStore::UVP_SHORT_PARAGRAPH] = $formState['uvp_short_paragraph'] ?? '';
        }
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
            $raw = $formState['jewelry_material'] ?? '';
            if (is_array($raw)) {
                $raw = implode('; ', array_values(array_unique(array_filter(array_map(
                    fn ($v) => trim((string) $v),
                    $raw
                )))));
            }
            $data[HeaderStore::JEWELRY_MATERIAL] = $raw;
        }
        if (array_key_exists('jewelry_type', $formState)) {
            $data[HeaderStore::JEWELRY_TYPE] = $formState['jewelry_type'] ?? '';
        }
        if (array_key_exists('bracelet_design', $formState)) {
            $data[HeaderStore::BRACELET_DESIGN] = $formState['bracelet_design'] ?? '';
        }
        if (array_key_exists('necklace_design', $formState)) {
            $data['Necklace design (product.metafields.shopify.necklace-design)'] = $formState['necklace_design'] ?? '';
        }
        if (array_key_exists('earring_design', $formState)) {
            $data['Earring design (product.metafields.shopify.earring-design)'] = $formState['earring_design'] ?? '';
        }
        if (array_key_exists('pattern_category', $formState)) {
            $data[HeaderStore::PATTERN_CATEGORY] = $formState['pattern_category'] ?? '';
        }
        if (array_key_exists('product_metals', $formState)) {
            $data[HeaderStore::PRODUCT_METALS] = $formState['product_metals'] ?? '';
        }
        if (array_key_exists('cost_per_item', $formState)) {
            $data['Cost per item'] = $formState['cost_per_item'] ?? '';
        }
        foreach ($rows as $row) {
            $row->data = $data;
            $row->save();
        }

        if (array_key_exists('variant_weight_unit', $formState)) {
            $normalized = trim((string) ($formState['variant_weight_unit'] ?? ''));
            $this->record->variants()->update([
                'weight_unit' => $normalized === '' ? null : $normalized,
            ]);
        }
        if (array_key_exists('variant_price', $formState)) {
            $raw = trim((string) ($formState['variant_price'] ?? ''));
            if ($raw !== '') {
                $this->record->variants()->update([
                    'price' => $raw,
                ]);
            }
        }

        app(\App\Services\Normalizer::class)->recalculateErrorsForProduct($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->disabled(fn (): bool => $this->getRecord()->has_errors || $this->getRecord()->isApprovedByTwo())
                ->action(function (): void {
                    ProductResource::approveRecord($this->getRecord());
                }),
            Actions\Action::make('renameImages')
                ->label('Rename Images')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->requiresConfirmation()
                ->disabled(fn (): bool => !$this->getRecord()->isApprovedByTwo() || !$this->getRecord()->images()->exists())
                ->action(function (): void {
                    if (!$this->getRecord()->isApprovedByTwo()) {
                        \Filament\Notifications\Notification::make()
                            ->title('Approval required')
                            ->body('Rename Images is only available after the product has 2 approvals.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $count = app(\App\Services\ProductImageFilenameService::class)
                        ->assignFromCurrentTitle($this->getRecord(), manual: true);

                    \Filament\Notifications\Notification::make()
                        ->title('Image filenames updated')
                        ->body($count > 0
                            ? "Updated {$count} image filename(s)."
                            : 'No image filenames needed updating.')
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make()
                ->visible(fn () => ProductResource::canDelete($this->getRecord())),
        ];
    }
}
