<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;

final class InventoryDraftMirrorService
{
    public function syncProduct(Product $product): void
    {
        if (!$product->handle && !$product->shopify_id) {
            return;
        }

        $draft = $this->findDraftForProduct($product);
        if (!$draft instanceof NewProductDraft) {
            return;
        }

        $primaryVariant = $product->relationLoaded('variants')
            ? $product->variants->sortBy('id')->first()
            : $product->variants()->orderBy('id')->first();

        $payload = [
            'status' => $this->nullIfEmpty($product->status),
            'variant_inventory_qty' => $primaryVariant instanceof Variant && $primaryVariant->inventory_tracked === false
                ? null
                : ($primaryVariant instanceof Variant && $primaryVariant->inventory_qty !== null
                    ? (int) $primaryVariant->inventory_qty
                    : null),
        ];

        $updates = [];
        foreach ($payload as $field => $value) {
            if ($draft->getAttribute($field) !== $value) {
                $updates[$field] = $value;
            }
        }

        if ($updates === []) {
            return;
        }

        NewProductDraft::withoutEvents(function () use ($draft, $updates): void {
            $draft->forceFill($updates)->save();
        });
    }

    private function findDraftForProduct(Product $product): ?NewProductDraft
    {
        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $draft = NewProductDraft::query()
                ->where('shopify_id', $shopifyId)
                ->first();

            if ($draft instanceof NewProductDraft) {
                return $draft;
            }
        }

        $handle = trim((string) ($product->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        $draft = NewProductDraft::query()
            ->where('handle', $handle)
            ->first();

        return $draft instanceof NewProductDraft ? $draft : null;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
