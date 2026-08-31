<?php

namespace App\Services\Shopify;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackComponentDeduction;
use App\Models\Variant;
use Illuminate\Support\Str;

final class StackFulfillmentInventoryService
{
    public function __construct(private readonly ShopifyInventoryAdjustmentService $inventory) {}

    /** @return array{stack_lines:int,deductions:int,already_completed:int} */
    public function process(ShopifyFulfillment $fulfillment): array
    {
        if (strtolower(trim((string) $fulfillment->shopify_status)) !== 'success') {
            $fulfillment->forceFill([
                'processing_status' => ShopifyFulfillment::STATUS_IGNORED,
                'error_message' => 'Fulfillment status is not success; no inventory was deducted.',
                'processed_at' => now(),
            ])->save();

            return ['stack_lines' => 0, 'deductions' => 0, 'already_completed' => 0];
        }

        $fulfillment->forceFill([
            'processing_status' => ShopifyFulfillment::STATUS_PROCESSING,
            'attempts' => (int) $fulfillment->attempts + 1,
            'processing_started_at' => now(),
            'error_message' => null,
        ])->save();

        $summary = ['stack_lines' => 0, 'deductions' => 0, 'already_completed' => 0];
        $errors = [];

        foreach ((array) data_get($fulfillment->payload, 'line_items', []) as $lineItem) {
            if (! is_array($lineItem) || (int) ($lineItem['quantity'] ?? 0) <= 0) {
                continue;
            }

            $stackVariant = $this->findVariant($lineItem['variant_id'] ?? null);
            if (! $stackVariant instanceof Variant || ! $stackVariant->product instanceof Product) {
                continue;
            }

            $draft = $this->findStackDraft($stackVariant, $lineItem);
            $isStack = (bool) $stackVariant->product->is_bundle || $draft instanceof NewProductDraft;
            if (! $isStack) {
                continue;
            }

            $summary['stack_lines']++;
            if (! $draft instanceof NewProductDraft) {
                $errors[] = "Stack variant {$stackVariant->shopify_id} has no linked Stack draft configuration.";

                continue;
            }

            $components = $this->componentConfiguration($draft);
            if ($components === []) {
                $errors[] = "Stack draft {$draft->id} has no configured components.";

                continue;
            }

            $lineItemId = trim((string) ($lineItem['fulfillment_line_item_id'] ?? $lineItem['id'] ?? ''));
            if ($lineItemId === '') {
                $errors[] = "Stack variant {$stackVariant->shopify_id} has no fulfillment line-item identifier.";

                continue;
            }

            foreach ($components as $componentProductId => $quantityPerStack) {
                try {
                    $result = $this->processComponent(
                        $fulfillment,
                        $lineItemId,
                        $stackVariant,
                        (int) $lineItem['quantity'],
                        $componentProductId,
                        $quantityPerStack,
                    );
                    $summary[$result]++;
                } catch (\Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        if ($errors !== []) {
            $message = implode(' | ', array_values(array_unique($errors)));
            $fulfillment->forceFill([
                'processing_status' => ShopifyFulfillment::STATUS_FAILED,
                'error_message' => $message,
            ])->save();

            throw new \RuntimeException($message);
        }

        $fulfillment->forceFill([
            'processing_status' => ShopifyFulfillment::STATUS_COMPLETED,
            'processed_at' => now(),
            'error_message' => null,
        ])->save();

        return $summary;
    }

    private function processComponent(
        ShopifyFulfillment $fulfillment,
        string $lineItemId,
        Variant $stackVariant,
        int $stackQuantity,
        int $componentProductId,
        int $quantityPerStack,
    ): string {
        $component = Product::query()->with('variants')->find($componentProductId);
        $deduct = $stackQuantity * $quantityPerStack;
        $locationId = $this->gid('Location', $fulfillment->shopify_location_id);

        $ledger = ShopifyStackComponentDeduction::query()->firstOrCreate(
            [
                'shopify_fulfillment_id' => $fulfillment->id,
                'shopify_fulfillment_line_item_id' => $lineItemId,
                'configured_component_product_id' => $componentProductId,
            ],
            [
                'shopify_order_id' => $fulfillment->shopify_order_id,
                'shopify_stack_variant_id' => (string) $stackVariant->shopify_id,
                'stack_product_id' => $stackVariant->product_id,
                'stack_variant_id' => $stackVariant->id,
                'stack_quantity_fulfilled' => $stackQuantity,
                'component_product_id' => $component?->id,
                'shopify_location_id' => $locationId,
                'component_quantity_per_stack' => $quantityPerStack,
                'quantity_deducted' => $deduct,
                'idempotency_key' => (string) Str::uuid(),
                'status' => ShopifyStackComponentDeduction::STATUS_PENDING,
            ],
        );

        if ($ledger->status === ShopifyStackComponentDeduction::STATUS_COMPLETED) {
            return 'already_completed';
        }

        $locationId = trim((string) $ledger->shopify_location_id);

        if (! $component instanceof Product) {
            $message = "Stack component product {$componentProductId} could not be found.";
            $this->failLedger($ledger, $message);
            throw new \RuntimeException($message);
        }

        $componentVariants = $component->variants
            ->filter(fn (Variant $variant): bool => trim((string) $variant->shopify_inventory_item_id) !== '')
            ->values();
        if ($componentVariants->count() !== 1) {
            $message = "Stack component product {$componentProductId} must have exactly one active variant with a Shopify inventory item ID.";
            $ledger->forceFill(['component_product_id' => $component->id])->save();
            $this->failLedger($ledger, $message);
            throw new \RuntimeException($message);
        }

        /** @var Variant $variant */
        $variant = $componentVariants->first();

        $ledger->forceFill([
            'status' => ShopifyStackComponentDeduction::STATUS_PROCESSING,
            'attempts' => (int) $ledger->attempts + 1,
            'processing_started_at' => now(),
            'component_product_id' => $component->id,
            'component_variant_id' => $variant->id,
            'shopify_component_variant_id' => $variant->shopify_id,
            'shopify_inventory_item_id' => $variant->shopify_inventory_item_id,
            'error_message' => null,
        ])->save();

        try {
            $reference = 'gid://shopify/Fulfillment/'.$this->numericId($fulfillment->shopify_fulfillment_id)
                .'#stack-component-'.$ledger->id;
            $response = $this->inventory->decreaseAvailable(
                $variant,
                (int) $ledger->quantity_deducted,
                $reference,
                (string) $ledger->idempotency_key,
                $locationId !== '' ? $locationId : null,
            );

            $ledger->forceFill([
                'status' => ShopifyStackComponentDeduction::STATUS_COMPLETED,
                'shopify_response' => $response,
                'processed_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $ledger->forceFill([
                'status' => ShopifyStackComponentDeduction::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }

        return 'deductions';
    }

    private function failLedger(ShopifyStackComponentDeduction $ledger, string $message): void
    {
        $ledger->forceFill([
            'status' => ShopifyStackComponentDeduction::STATUS_FAILED,
            'attempts' => (int) $ledger->attempts + 1,
            'error_message' => $message,
        ])->save();
    }

    private function findVariant(mixed $shopifyVariantId): ?Variant
    {
        $id = trim((string) ($shopifyVariantId ?? ''));
        if ($id === '') {
            return null;
        }

        return Variant::query()
            ->with('product')
            ->whereIn('shopify_id', array_unique([$id, $this->gid('ProductVariant', $id)]))
            ->first();
    }

    private function findStackDraft(Variant $variant, array $lineItem): ?NewProductDraft
    {
        $product = $variant->product;
        $shopifyId = trim((string) ($product?->shopify_id ?? ''));
        $handle = trim((string) ($product?->handle ?? ''));
        $sku = trim((string) ($variant->sku ?: ($lineItem['sku'] ?? '')));

        if ($shopifyId === '' && $handle === '' && $sku === '') {
            return null;
        }

        return NewProductDraft::query()
            ->where(function ($query) use ($shopifyId, $handle, $sku): void {
                $hasCondition = false;
                foreach (['shopify_id' => $shopifyId, 'handle' => $handle, 'sku' => $sku] as $column => $value) {
                    if ($value === '') {
                        continue;
                    }

                    $hasCondition
                        ? $query->orWhere($column, $value)
                        : $query->where($column, $value);
                    $hasCondition = true;
                }
            })
            ->whereNotNull('bundle_product_ids')
            ->first();
    }

    /** @return array<int, int> */
    private function componentConfiguration(NewProductDraft $draft): array
    {
        $configured = collect((array) $draft->bundle_component_quantities)
            ->mapWithKeys(function (mixed $row): array {
                if (! is_array($row)) {
                    return [];
                }
                $productId = (int) ($row['product_id'] ?? 0);
                $quantity = max(1, (int) ($row['quantity'] ?? 1));

                return $productId > 0 ? [$productId => $quantity] : [];
            })
            ->all();

        foreach ((array) $draft->bundle_product_ids as $productId) {
            $productId = (int) $productId;
            if ($productId > 0 && ! isset($configured[$productId])) {
                $configured[$productId] = 1;
            }
        }

        return $configured;
    }

    private function gid(string $type, mixed $id): string
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '') {
            return '';
        }

        return str_starts_with($id, 'gid://shopify/') ? $id : "gid://shopify/{$type}/{$id}";
    }

    private function numericId(mixed $id): string
    {
        $parts = explode('/', trim((string) $id));

        return (string) end($parts);
    }
}
