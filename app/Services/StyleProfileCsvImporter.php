<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StyleProfile;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

final class StyleProfileCsvImporter
{
    /**
     * @return array{total:int, imported:int, skipped_no_handle:int, unlinked_no_product:int}
     */
    public function importFromPath(string $absolutePath): array
    {
        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $headerMap = [];
        foreach ($csv->getHeader() as $header) {
            $headerMap[$this->normalizeHeader($header)] = $header;
        }

        $map = [
            'image' => 'image_url',
            'sku' => 'sku',
            'handle' => 'handle',
            'product handle' => 'handle',
            'style' => 'style_type',
            'materials' => 'materials',
            'components' => 'components',
            'colour prompt' => 'colour_prompt',
            'color prompt' => 'colour_prompt',
            'title' => 'draft_title',
            'description' => 'draft_description',
            'seo title' => 'draft_seo_title',
            'seo title 60 chars' => 'draft_seo_title',
            'seo title 70 chars' => 'draft_seo_title',
            'seo description 160 chars' => 'draft_seo_description',
            'seo description' => 'draft_seo_description',
            'image alt text 125 chars' => 'draft_image_alt_text',
            'image alt text' => 'draft_image_alt_text',
        ];

        $total = 0;
        $imported = 0;
        $skippedNoHandle = 0;
        $unlinkedNoProduct = 0;

        DB::transaction(function () use (
            $csv,
            $headerMap,
            $map,
            &$total,
            &$imported,
            &$skippedNoHandle,
            &$unlinkedNoProduct
        ): void {
            foreach ($csv->getRecords() as $row) {
                $total++;

                $data = [];
                foreach ($map as $normalizedHeader => $field) {
                    $header = $headerMap[$normalizedHeader] ?? null;
                    if (!$header) {
                        continue;
                    }

                    $value = trim((string)($row[$header] ?? ''));
                    $data[$field] = $value !== '' ? $value : null;
                }

                $handle = $data['handle'] ?? null;
                if (!$handle) {
                    $skippedNoHandle++;
                    continue;
                }

                $sku = $data['sku'] ?? null;
                if (!$sku) {
                    $sku = $handle;
                }

                $product = Product::where('handle', $handle)->first();
                if ($product) {
                    $data['product_id'] = $product->id;
                } else {
                    $data['product_id'] = null;
                    $unlinkedNoProduct++;
                }
                $data['handle'] = $handle;
                $data['sku'] = $sku;

                $existing = StyleProfile::where('handle', $handle)->first();
                if ($existing) {
                    $existing->update($data);
                } else {
                    StyleProfile::create($data);
                }

                $imported++;
            }
        });

        return [
            'total' => $total,
            'imported' => $imported,
            'skipped_no_handle' => $skippedNoHandle,
            'unlinked_no_product' => $unlinkedNoProduct,
        ];
    }

    private function normalizeHeader(string $header): string
    {
        $lower = strtolower(trim($header));
        $lower = preg_replace('/[^\\x20-\\x7E]/', '', $lower);
        $lower = preg_replace('/[^a-z0-9]+/', ' ', $lower);
        return trim($lower);
    }
}
