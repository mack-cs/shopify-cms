<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductTagProvenance;
use Illuminate\Database\Eloquent\Model;

/**
 * Shopify-cached tags stay Shopify-sourced. Locally planned draft tags are recorded separately.
 * Provenance never decides collection membership.
 */
class ProductTagProvenanceService
{
    public function sourceFor(Model $model, string $tag): string
    {
        $key = $this->key($tag);
        $row = $key === '' ? null : ProductTagProvenance::query()
            ->where('taggable_type', $model::class)
            ->where('taggable_id', $model->getKey())
            ->where('tag', $key)
            ->first();
        if ($row instanceof ProductTagProvenance) {
            return $row->source;
        }
        if ($model instanceof Product && filled($model->shopify_id)) {
            return ProductTagProvenance::SHOPIFY;
        }
        if ($model instanceof NewProductDraft && ($this->draftCameFromShopify($model))) {
            return ProductTagProvenance::SHOPIFY;
        }
        if ($model instanceof NewProductDraft) {
            return ProductTagProvenance::PLANNED;
        }

        return ProductTagProvenance::SHOPIFY;
    }

    /** @param array<int, string> $labels */
    public function sync(Model $model, array $labels, string $source): void
    {
        if (! in_array($source, [ProductTagProvenance::SHOPIFY, ProductTagProvenance::PLANNED], true)) {
            throw new \InvalidArgumentException('Tag provenance must be shopify or planned.');
        }
        foreach ($labels as $label) {
            $label = trim((string) $label);
            $key = $this->key($label);
            if ($key === '') {
                continue;
            }
            ProductTagProvenance::query()->updateOrCreate(
                ['taggable_type' => $model::class, 'taggable_id' => $model->getKey(), 'tag' => $key],
                ['label' => $label, 'source' => $source],
            );
        }
    }

    public function syncDraft(NewProductDraft $draft): void
    {
        $this->sync(
            $draft,
            TagNormalizer::parseTokens((string) $draft->tags),
            $this->draftCameFromShopify($draft) ? ProductTagProvenance::SHOPIFY : ProductTagProvenance::PLANNED,
        );
    }

    private function draftCameFromShopify(NewProductDraft $draft): bool
    {
        return filled($draft->shopify_id)
            || in_array($draft->origin, [NewProductDraft::ORIGIN_SHOPIFY_SEED, NewProductDraft::ORIGIN_PRODUCT_MIRROR], true);
    }

    private function key(string $tag): string
    {
        return mb_strtolower(trim($tag));
    }
}
