<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Services\HeaderStore;

final class NewProductDraftSeeder
{
    /**
     * @return array{created:int, skipped:int}
     */
    public function seedMissingFromProducts(int $importId, ?int $userId = null): array
    {
        $created = 0;
        $skipped = 0;

        Product::query()
            ->where('import_id', $importId)
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('new_product_drafts')
                    ->whereColumn('new_product_drafts.handle', 'products.handle');
            })
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$created, &$skipped, $userId): void {
                foreach ($products as $product) {
                    $imageUrl = $product->images()
                        ->orderBy('position')
                        ->value('src');

                    $row = \App\Models\ShopifyRow::where('import_id', $product->import_id)
                        ->where('handle', $product->handle)
                        ->where('row_type', 'product_primary')
                        ->first();

                    $data = [
                        'handle' => $product->handle,
                        'title' => $product->title,
                        'body_html' => $product->body_html,
                        'vendor' => $product->vendor,
                        'tags' => $product->tags,
                        'type' => $product->type,
                        'published' => $product->published,
                        'product_category' => $product->product_category,
                        'google_product_category' => $product->google_product_category,
                        'status' => $product->status,
                        'color_string' => $product->color_string,
                        'batch' => $product->batch,
                        'image_url' => $imageUrl,
                        'created_by' => $userId,
                    ];

                    if ($row) {
                        $data['material_cost'] = $row->get(HeaderStore::MATERIAL_COST, null);
                        $data['jewelry_material'] = $row->get(HeaderStore::JEWELRY_MATERIAL, null);
                        $data['product_materials'] = $row->get(HeaderStore::PRODUCT_MATERIALS, null);
                        $data['materials_and_dimensions'] = $row->get(HeaderStore::MATERIALS_AND_DIMENSIONS, null);
                        $data['product_design'] = $row->get(HeaderStore::BRACELET_DESIGN, null);
                        $data['metal'] = $row->get(HeaderStore::PRODUCT_METALS, null);
                        $data['colour_style'] = $row->get(HeaderStore::PATTERN_CATEGORY, null);
                        $data['size'] = $row->get(HeaderStore::SIZE, null);
                        $data['siblings'] = $row->get(HeaderStore::SIBLINGS, null);
                        $data['siblings_collection_name'] = $row->get(HeaderStore::SIBLINGS_COLLECTION_NAME, null);
                        $data['complementary_products'] = $row->get(HeaderStore::COMPLEMENTARY_PRODUCTS, null);
                    }

                    NewProductDraft::create($data);
                    $created++;
                }
            });

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }
}
