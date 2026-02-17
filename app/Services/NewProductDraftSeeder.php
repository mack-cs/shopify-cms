<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;

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
                        'seo_title' => $product->seo_title,
                        'seo_description' => $product->seo_description,
                        'color_string' => $product->color_string,
                        'batch' => $product->batch,
                        'image_url' => $imageUrl,
                        'created_by' => $userId,
                    ];

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
