<?php

namespace App\Services;

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use Illuminate\Support\Collection;

final class NewProductDraftProductSync
{
    /**
     * @param Collection<int, NewProductDraft>|null $drafts
     * @return array{updated:int, created:int, skipped_unapproved:int, skipped_missing_handle:int, skipped_missing_import:int}
     */
    public function syncApprovedDrafts(?Collection $drafts = null): array
    {
        $updated = 0;
        $created = 0;
        $skippedUnapproved = 0;
        $skippedMissingHandle = 0;
        $skippedMissingImport = 0;

        $drafts = $drafts ?? NewProductDraft::query()
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->get();

        foreach ($drafts as $draft) {
            if (!$draft instanceof NewProductDraft) {
                continue;
            }

            if (!$draft->handle) {
                $skippedMissingHandle++;
                continue;
            }

            if (!$draft->isApprovedByTwo()) {
                $skippedUnapproved++;
                continue;
            }

            $product = Product::query()
                ->where('handle', $draft->handle)
                ->first();

            $data = $this->mapDraftToProduct($draft);

            if ($product) {
                $product->fill($data)->save();
                $updated++;
                continue;
            }

            $import = Import::where('is_current', true)->first();
            if (!$import) {
                $skippedMissingImport++;
                continue;
            }

            Product::create(array_merge(
                ['import_id' => $import->id, 'handle' => $draft->handle],
                $data
            ));
            $created++;
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'skipped_unapproved' => $skippedUnapproved,
            'skipped_missing_handle' => $skippedMissingHandle,
            'skipped_missing_import' => $skippedMissingImport,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDraftToProduct(NewProductDraft $draft): array
    {
        return array_filter([
            'title' => $draft->title,
            'body_html' => $draft->body_html,
            'vendor' => $draft->vendor,
            'tags' => $draft->tags,
            'type' => $draft->type,
            'product_category' => $draft->product_category,
            'google_product_category' => $draft->google_product_category,
            'status' => $draft->status,
            'published' => $draft->published,
            'seo_title' => $draft->seo_title,
            'seo_description' => $draft->seo_description,
            'color_string' => $draft->color_string,
            'batch' => $draft->batch,
        ], static fn ($value) => $value !== null);
    }
}
