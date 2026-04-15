<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\StyleProfile;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use SplTempFileObject;

final class NewProductDraftRoundtripCsvService
{
    /**
     * @return array<string, string>
     */
    public function exportColumnOptions(): array
    {
        $options = [];

        foreach ($this->columnDefinitions() as $key => $definition) {
            $options[$key] = $definition['label'];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function defaultExportColumns(): array
    {
        return array_keys($this->columnDefinitions());
    }

    /**
     * @param iterable<int, NewProductDraft> $records
     * @param array<int, string> $selectedColumns
     * @return array{disk:string,path:string,row_count:int,column_count:int,skipped_without_handle:int}
     */
    public function exportDrafts(iterable $records, array $selectedColumns): array
    {
        $selectedDrafts = collect($records)
            ->filter(fn ($record): bool => $record instanceof NewProductDraft)
            ->values();

        if ($selectedDrafts->isEmpty()) {
            throw new \InvalidArgumentException('Select at least one draft.');
        }

        $drafts = $selectedDrafts
            ->filter(fn (NewProductDraft $draft): bool => trim((string) ($draft->handle ?? '')) !== '')
            ->values();

        $skippedWithoutHandle = $selectedDrafts->count() - $drafts->count();

        if ($drafts->isEmpty()) {
            throw new \InvalidArgumentException('None of the selected drafts have handles. Handle-less drafts must be edited inside the tool.');
        }

        $columnDefinitions = $this->columnDefinitions();
        $columns = array_values(array_unique(array_filter($selectedColumns, fn (string $key): bool => isset($columnDefinitions[$key]))));
        if ($columns === []) {
            throw new \InvalidArgumentException('Choose at least one export column.');
        }

        $headers = ['Draft ID', 'Handle', 'Shopify ID'];
        foreach ($columns as $key) {
            $headers[] = $columnDefinitions[$key]['label'];
        }

        $styleProfiles = StyleProfile::query()
            ->whereIn('handle', $drafts->pluck('handle')->filter()->all())
            ->get()
            ->keyBy('handle');

        $writer = Writer::createFromFileObject(new SplTempFileObject());
        $writer->insertOne($headers);

        foreach ($drafts as $draft) {
            $styleProfile = $draft->handle ? $styleProfiles->get($draft->handle) : null;
            $row = [
                'Draft ID' => (string) $draft->getKey(),
                'Handle' => trim((string) ($draft->handle ?? '')),
                'Shopify ID' => trim((string) ($draft->shopify_id ?? '')),
            ];

            foreach ($columns as $key) {
                $row[$columnDefinitions[$key]['label']] = $this->valueForColumn($key, $draft, $styleProfile);
            }

            $writer->insertOne(array_map(
                fn (string $header): string => (string) ($row[$header] ?? ''),
                $headers
            ));
        }

        $timestamp = now()->format('Ymd_His');
        $path = "draft-exports/new_product_roundtrip_{$timestamp}.csv";
        Storage::disk('public')->put($path, $writer->toString());

        return [
            'disk' => 'public',
            'path' => $path,
            'row_count' => $drafts->count(),
            'column_count' => count($columns),
            'skipped_without_handle' => $skippedWithoutHandle,
        ];
    }

    private function valueForColumn(string $key, NewProductDraft $draft, ?StyleProfile $styleProfile): string
    {
        return match ($key) {
            'sku' => trim((string) ($draft->sku ?? '')),
            'title' => trim((string) ($draft->title ?? '')),
            'vendor' => trim((string) ($draft->vendor ?? '')),
            'type' => trim((string) ($draft->type ?? '')),
            'status' => trim((string) ($draft->status ?? '')),
            'batch' => trim((string) ($draft->batch ?? '')),
            'published' => trim((string) ($draft->published ?? '')),
            'product_category' => trim((string) ($draft->product_category ?? '')),
            'google_product_category' => trim((string) ($draft->google_product_category ?? '')),
            'color_string' => trim((string) ($draft->color_string ?? '')),
            'tags' => trim((string) ($draft->tags ?? '')),
            'body_html' => trim((string) ($draft->body_html ?? '')),
            'variant_price' => trim((string) ($draft->variant_price ?? '')),
            'variant_compare_at_price' => trim((string) ($draft->variant_compare_at_price ?? '')),
            'variant_inventory_qty' => trim((string) ($draft->variant_inventory_qty ?? '')),
            'material_cost' => trim((string) ($draft->material_cost ?? '')),
            'jewelry_material' => trim((string) ($draft->jewelry_material ?? '')),
            'product_materials' => trim((string) ($draft->product_materials ?? '')),
            'materials_and_dimensions' => trim((string) ($draft->materials_and_dimensions ?? '')),
            'product_design' => trim((string) ($draft->product_design ?? '')),
            'metal' => trim((string) ($draft->metal ?? '')),
            'colour_style' => trim((string) ($draft->colour_style ?? '')),
            'size' => trim((string) ($draft->size ?? '')),
            'siblings' => $this->productReferencesAsHandles($draft->siblings),
            'siblings_collection_name' => trim((string) ($draft->siblings_collection_name ?? '')),
            'sibling_collection' => trim((string) ($draft->sibling_collection ?? '')),
            'uvp_short_paragraph' => trim((string) ($draft->uvp_short_paragraph ?? '')),
            'complementary_products' => $this->productReferencesAsHandles($draft->complementary_products),
            'style_type' => trim((string) ($styleProfile?->style_type ?? '')),
            'draft_seo_title' => trim((string) ($styleProfile?->draft_seo_title ?? '')),
            'draft_seo_description' => trim((string) ($styleProfile?->draft_seo_description ?? '')),
            'style_materials' => trim((string) ($styleProfile?->materials ?? '')),
            'style_components' => trim((string) ($styleProfile?->components ?? '')),
            'style_colour_prompt' => trim((string) ($styleProfile?->colour_prompt ?? '')),
            'draft_title' => trim((string) ($styleProfile?->draft_title ?? '')),
            'draft_description' => trim((string) ($styleProfile?->draft_description ?? '')),
            'draft_image_alt_text' => trim((string) ($styleProfile?->draft_image_alt_text ?? '')),
            default => '',
        };
    }

    /**
     * @return array<string, array{label:string}>
     */
    private function columnDefinitions(): array
    {
        return [
            'title' => ['label' => 'Title'],
            'body_html' => ['label' => 'Description'],
            'vendor' => ['label' => 'Vendor'],
            'type' => ['label' => 'Type'],
            'status' => ['label' => 'Status'],
            'batch' => ['label' => 'Batch'],
            'published' => ['label' => 'Published'],
            'product_category' => ['label' => 'Product Category'],
            'google_product_category' => ['label' => 'Google Product Category'],
            'color_string' => ['label' => 'Colors'],
            'tags' => ['label' => 'Tags'],
            'sku' => ['label' => 'SKU'],
            'variant_price' => ['label' => 'Price'],
            'variant_compare_at_price' => ['label' => 'Compare-at Price'],
            'variant_inventory_qty' => ['label' => 'Inventory'],
            'material_cost' => ['label' => 'Material Cost'],
            'jewelry_material' => ['label' => 'Jewelry Material'],
            'product_materials' => ['label' => 'Product Materials'],
            'materials_and_dimensions' => ['label' => 'Materials and Dimensions'],
            'product_design' => ['label' => 'Product Design'],
            'metal' => ['label' => 'Metal'],
            'colour_style' => ['label' => 'Color Style'],
            'size' => ['label' => 'Size'],
            'siblings' => ['label' => 'Siblings'],
            'siblings_collection_name' => ['label' => 'Siblings Collection Name'],
            'sibling_collection' => ['label' => 'Sibling Collection'],
            'uvp_short_paragraph' => ['label' => 'UVP Short Paragraph'],
            'complementary_products' => ['label' => 'Complementary Products'],
            'style_type' => ['label' => 'Style'],
            'draft_seo_title' => ['label' => 'SEO Title'],
            'draft_seo_description' => ['label' => 'SEO Description'],
            'style_materials' => ['label' => 'Style Materials'],
            'style_components' => ['label' => 'Style Components'],
            'style_colour_prompt' => ['label' => 'Colour Prompt'],
            'draft_title' => ['label' => 'Draft Title'],
            'draft_description' => ['label' => 'Draft Description'],
            'draft_image_alt_text' => ['label' => 'Draft Image Alt Text'],
        ];
    }

    private function productReferencesAsHandles(?string $value): string
    {
        $tokens = $this->parseReferenceTokens($value);
        if ($tokens === []) {
            return '';
        }

        $productsByShopifyId = Product::query()
            ->whereIn('shopify_id', $tokens)
            ->get(['shopify_id', 'handle'])
            ->keyBy(fn (Product $product): string => trim((string) ($product->shopify_id ?? '')));

        $handles = [];
        foreach ($tokens as $token) {
            $product = $productsByShopifyId->get($token);
            $handle = trim((string) ($product?->handle ?? ''));
            $handles[] = $handle !== '' ? $handle : $token;
        }

        return implode('; ', array_values(array_unique(array_filter($handles))));
    }

    /**
     * @return array<int, string>
     */
    private function parseReferenceTokens(?string $value): array
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return [];
        }

        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->parseReferenceTokens(implode('; ', array_map('strval', $decoded)));
            }
        }

        $parts = str_contains($raw, ';')
            ? explode(';', $raw)
            : explode(',', $raw);

        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            $parts
        ), static fn (string $item): bool => $item !== '')));
    }
}
