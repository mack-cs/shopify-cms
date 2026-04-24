<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\NewProductDraft;
use App\Models\ShopifyMissingProduct;

final class ShopifyMissingDraftWorkflowService
{
    public function flagFromMissingProducts(int $importId): int
    {
        $missingProducts = ShopifyMissingProduct::query()
            ->where('import_id', $importId)
            ->get();

        $flagged = 0;

        foreach ($missingProducts as $missingProduct) {
            $draft = NewProductDraft::query()
                ->when(
                    filled(trim((string) ($missingProduct->shopify_id ?? ''))),
                    fn ($query) => $query->where('shopify_id', $missingProduct->shopify_id),
                    fn ($query) => $query->where('handle', $missingProduct->handle)
                )
                ->first();

            if (!$draft) {
                continue;
            }

            $changes = [
                'shopify_missing_detected_at' => now(),
                'shopify_missing_sync_blocked' => true,
            ];

            if (!$draft->shopify_missing_status || $draft->shopify_missing_status === NewProductDraft::SHOPIFY_MISSING_RECOVERY_ENABLED) {
                $changes['shopify_missing_status'] = NewProductDraft::SHOPIFY_MISSING_PENDING_REVIEW;
            }

            $shouldLog = !$draft->isBlockedFromShopifyMissing();

            $draft->fill($changes)->save();

            if ($shouldLog) {
                $this->log($draft, 'shopify_missing_detected', [
                    'status' => $draft->shopify_missing_status,
                    'handle' => $draft->handle,
                    'shopify_id' => $draft->shopify_id,
                    'detected_at' => $draft->shopify_missing_detected_at?->toDateTimeString(),
                ]);
                $flagged++;
            }
        }

        return $flagged;
    }

    public function investigate(NewProductDraft $draft, ?int $userId): void
    {
        $draft->forceFill([
            'shopify_missing_status' => NewProductDraft::SHOPIFY_MISSING_INVESTIGATING,
            'shopify_missing_sync_blocked' => true,
        ])->save();

        $this->log($draft, 'shopify_missing_investigating', [
            'status' => $draft->shopify_missing_status,
            'handle' => $draft->handle,
            'shopify_id' => $draft->shopify_id,
        ], $userId);
    }

    public function cleanLocalProduct(NewProductDraft $draft, ?int $userId): void
    {
        $product = $draft->product()->first();
        if ($product) {
            $product->delete();
        }

        $draft->forceFill([
            'shopify_missing_status' => NewProductDraft::SHOPIFY_MISSING_CLEANED,
            'shopify_missing_sync_blocked' => true,
        ])->save();

        $this->log($draft, 'shopify_missing_cleaned', [
            'status' => $draft->shopify_missing_status,
            'handle' => $draft->handle,
            'shopify_id' => $draft->shopify_id,
            'product_removed_locally' => $product !== null,
        ], $userId);
    }

    public function enableRecovery(NewProductDraft $draft, ?int $userId): void
    {
        $draft->forceFill([
            'shopify_missing_status' => NewProductDraft::SHOPIFY_MISSING_RECOVERY_ENABLED,
            'shopify_missing_sync_blocked' => false,
        ])->save();

        $this->log($draft, 'shopify_missing_recovery_enabled', [
            'status' => $draft->shopify_missing_status,
            'handle' => $draft->handle,
            'shopify_id' => $draft->shopify_id,
        ], $userId);
    }

    private function log(NewProductDraft $draft, string $field, array $payload, ?int $changedBy = null): void
    {
        ChangeLog::create([
            'import_id' => $draft->product?->import_id,
            'product_id' => $draft->product?->id,
            'changed_by' => $changedBy,
            'model_type' => NewProductDraft::class,
            'model_id' => $draft->id,
            'field' => $field,
            'old_value' => null,
            'new_value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
