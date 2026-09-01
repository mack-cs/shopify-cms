<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use App\Models\ChangeLog;
use Illuminate\Support\Collection;

final class ProductInventorySyncService
{
    public function __construct(
        private readonly ShopifyApiClient $client,
        private readonly ComplementaryDependencyService $dependencyService,
        private readonly ProductShopifyUpdater $shopifyUpdater,
    ) {
    }

    /**
     * @param Collection<int, Variant> $variants
     * @return array{
     *   synced:int,
     *   refreshed:int,
     *   failed:int,
     *   warnings:array<int, string>,
     *   failures:array<int, string>
     * }
     */
    public function syncVariants(Collection $variants, ?int $userId = null, ?string $syncBatchId = null): array
    {
        $synced = 0;
        $failed = 0;
        $warnings = [];
        $failures = [];

        $variantsByProduct = $variants
            ->filter(fn ($variant): bool => $variant instanceof Variant)
            ->groupBy('product_id');

        foreach ($variantsByProduct as $productId => $group) {
            $product = Product::find((int) $productId);
            if (!$product instanceof Product) {
                continue;
            }

            try {
                $this->syncProductVariants($product, $group->values(), $syncBatchId, $userId, $warnings);
                $synced += $group->count();
            } catch (\Throwable $e) {
                $failed += $group->count();
                foreach ($group as $variant) {
                    if ($variant instanceof Variant) {
                        $this->markInventorySyncFailed($variant, $e->getMessage());
                        $failures[] = "Variant {$variant->id}: {$e->getMessage()}";
                    }
                }
            }
        }

        return [
            'synced' => $synced,
            'refreshed' => 0,
            'failed' => $failed,
            'warnings' => $warnings,
            'failures' => $failures,
        ];
    }

    /**
     * @param Collection<int, Variant> $variants
     * @return array{
     *   synced:int,
     *   refreshed:int,
     *   failed:int,
     *   warnings:array<int, string>,
     *   failures:array<int, string>
     * }
     */
    public function refreshVariants(Collection $variants, ?int $userId = null): array
    {
        $refreshed = 0;
        $failed = 0;
        $warnings = [];
        $failures = [];

        $variantsByProduct = $variants
            ->filter(fn ($variant): bool => $variant instanceof Variant)
            ->groupBy('product_id');

        foreach ($variantsByProduct as $productId => $group) {
            $product = Product::find((int) $productId);
            if (!$product instanceof Product) {
                continue;
            }

            try {
                $this->refreshProductVariants($product, $group->values(), $userId);
                $refreshed += $group->count();
            } catch (\Throwable $e) {
                $failed += $group->count();
                foreach ($group as $variant) {
                    if ($variant instanceof Variant) {
                        $failures[] = "Variant {$variant->id}: {$e->getMessage()}";
                    }
                }
            }
        }

        return [
            'synced' => 0,
            'refreshed' => $refreshed,
            'failed' => $failed,
            'warnings' => $warnings,
            'failures' => $failures,
        ];
    }

    /**
     * @return array{
     *   synced:int,
     *   refreshed:int,
     *   failed:int,
     *   warnings:array<int, string>,
     *   failures:array<int, string>
     * }
     */
    public function refreshProduct(Product $product, ?int $userId = null): array
    {
        $variants = $product->variants()->orderBy('id')->get();

        try {
            $this->refreshProductVariants($product, $variants, $userId);

            return [
                'synced' => 0,
                'refreshed' => max(1, $variants->count()),
                'failed' => 0,
                'warnings' => [],
                'failures' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'synced' => 0,
                'refreshed' => 0,
                'failed' => max(1, $variants->count()),
                'warnings' => [],
                'failures' => ["Product {$product->id}: {$e->getMessage()}"],
            ];
        }
    }

    /**
     * @param Collection<int, Variant> $variants
     * @param array<int, string> $warnings
     */
    private function syncProductVariants(Product $product, Collection $variants, ?string $syncBatchId, ?int $userId, array &$warnings): void
    {
        $details = $this->shopifyProductInventoryDetails($product);
        $productId = trim((string) ($details['id'] ?? ''));
        if ($productId === '') {
            throw new \RuntimeException('Unable to resolve Shopify product for inventory sync.');
        }

        $remoteVariants = collect(data_get($details, 'variants.nodes', []))
            ->filter(fn ($node): bool => is_array($node))
            ->values();

        $variantsToSync = $variants
            ->filter(fn ($variant): bool => $variant instanceof Variant)
            ->filter(fn (Variant $variant): bool => $this->shouldPushVariantInventory($variant))
            ->values();

        $skippedUnknown = $variants->count() - $variantsToSync->count();
        if ($skippedUnknown > 0) {
            $warnings[] = "Skipped {$skippedUnknown} variant(s) with unknown inventory state and no local inventory change.";
        }

        foreach ($variantsToSync as $variant) {
            if (!$variant instanceof Variant) {
                continue;
            }

            $remoteVariant = $this->matchRemoteVariant($variant, $remoteVariants);
            if ($remoteVariant === null) {
                throw new \RuntimeException("Unable to resolve Shopify variant for SKU '{$variant->sku}'.");
            }

            $inventoryItemId = trim((string) data_get($remoteVariant, 'inventoryItem.id', ''));
            if ($inventoryItemId === '') {
                throw new \RuntimeException("Missing Shopify inventory item for SKU '{$variant->sku}'.");
            }

            $this->updateShopifyInventoryTracking($inventoryItemId, $variant->inventory_tracked);

            if ($variant->inventory_tracked !== false) {
                $this->updateShopifyOnHandQuantity(
                    $inventoryItemId,
                    (int) ($variant->current_on_hand_quantity ?? $variant->inventory_qty ?? 0),
                    (string) ($remoteVariant['id'] ?? '')
                );
            }

            $this->markInventorySynced($variant, $syncBatchId);
        }

        $this->updateShopifyProductStatus($productId, (string) $product->status);
        $this->refreshComplementaryParents($product, $userId, $warnings);
        $this->refreshProductVariants($product->fresh(), $variants, $userId);
    }

    private function shouldPushVariantInventory(Variant $variant): bool
    {
        if ($variant->inventory_local_dirty) {
            return true;
        }

        if ($variant->inventory_tracked === null) {
            return false;
        }

        if ($variant->inventory_tracked === false) {
            return false;
        }

        return $variant->inventory_qty !== null;
    }

    /**
     * @param Collection<int, Variant> $variants
     */
    private function refreshProductVariants(Product $product, Collection $variants, ?int $userId): void
    {
        $details = $this->shopifyProductInventoryDetails($product);
        $remoteStatus = strtolower(trim((string) ($details['status'] ?? '')));
        $remoteCreatedAt = trim((string) ($details['createdAt'] ?? ''));

        InventoryOperationContext::run(function () use ($product, $variants, $details, $remoteStatus, $remoteCreatedAt, $userId): void {
            $productChanged = false;
            if ($remoteStatus !== '' && $remoteStatus !== strtolower(trim((string) ($product->status ?? '')))) {
                $product->status = $remoteStatus;
                $productChanged = true;
            }
            if ($remoteCreatedAt !== '' && $product->shopify_created_at?->toIso8601String() !== $remoteCreatedAt) {
                $product->shopify_created_at = $remoteCreatedAt;
                $productChanged = true;
            }
            if ($productChanged) {
                $product->save();
            }

            $remoteVariants = collect(data_get($details, 'variants.nodes', []))
                ->filter(fn ($node): bool => is_array($node))
                ->values();

            foreach ($variants as $variant) {
                if (!$variant instanceof Variant) {
                    continue;
                }

                $remoteVariant = $this->matchRemoteVariant($variant, $remoteVariants);
                if ($remoteVariant === null) {
                    continue;
                }

                $tracked = data_get($remoteVariant, 'inventoryItem.tracked');
                $quantities = $this->inventoryQuantities($remoteVariant);
                $available = $tracked === false
                    ? null
                    : ($quantities['available'] ?? $this->normalizeRemoteInventoryQuantity(data_get($remoteVariant, 'inventoryQuantity')));

                $updates = [
                    'shopify_id' => trim((string) ($remoteVariant['id'] ?? '')) ?: $variant->shopify_id,
                    'shopify_inventory_item_id' => $this->normalizeShopifyInventoryItemId(data_get($remoteVariant, 'inventoryItem.id')),
                    'shopify_available_for_sale' => data_get($remoteVariant, 'availableForSale'),
                    'price' => $this->normalizeRemoteMoney(data_get($remoteVariant, 'price')),
                    'compare_at_price' => $this->normalizeRemoteMoney(data_get($remoteVariant, 'compareAtPrice')),
                    'inventory_tracked' => $tracked,
                    'inventory_qty' => $available,
                    'current_inventory_quantity' => $available,
                    'current_available_quantity' => $available,
                    'current_on_hand_quantity' => $tracked === false ? null : ($quantities['on_hand'] ?? null),
                    'current_committed_quantity' => $tracked === false ? null : ($quantities['committed'] ?? null),
                    'inventory_location_count' => $tracked === false ? 0 : $this->inventoryLocationCount($remoteVariant),
                    'inventory_local_dirty' => false,
                    'inventory_sync_error' => null,
                    'inventory_last_synced_at' => now(),
                ];

                $this->applyRemoteVariantRefresh($variant, $updates, $userId, $product);
            }
        });

        app(InventoryDraftMirrorService::class)->syncProduct($product->fresh(['variants']));
        $freshProduct = $product->fresh(['variants']);

        if ($freshProduct instanceof Product) {
            app(ProductInventoryHistoryRecorder::class)->record(
                $freshProduct,
                $userId,
                ProductInventorySnapshot::SOURCE_SHOPIFY_REFRESH,
            );
        }
    }

    private function shopifyProductInventoryDetails(Product $product): array
    {
        $productId = trim((string) ($product->shopify_id ?? ''));

        if ($productId !== '') {
            $data = $this->client->graphql($this->productInventoryByIdQuery(), [
                'id' => $productId,
            ]);

            return data_get($data, 'product', []) ?: [];
        }

        $handle = trim((string) ($product->handle ?? ''));
        if ($handle === '') {
            return [];
        }

        $data = $this->client->graphql($this->productInventoryByHandleQuery(), [
            'handle' => $handle,
        ]);

        return data_get($data, 'productByHandle', []) ?: [];
    }

    /**
     * @param Collection<int, array> $remoteVariants
     */
    private function matchRemoteVariant(Variant $variant, Collection $remoteVariants): ?array
    {
        $shopifyId = trim((string) ($variant->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $matched = $remoteVariants->first(
                fn (array $node): bool => trim((string) ($node['id'] ?? '')) === $shopifyId
            );
            if (is_array($matched)) {
                return $matched;
            }
        }

        $sku = trim((string) ($variant->sku ?? ''));
        if ($sku !== '') {
            $matched = $remoteVariants->first(
                fn (array $node): bool => trim((string) ($node['sku'] ?? '')) === $sku
            );
            if (is_array($matched)) {
                return $matched;
            }
        }

        return null;
    }

    private function updateShopifyInventoryTracking(string $inventoryItemId, ?bool $tracked): void
    {
        if ($tracked === null) {
            return;
        }

        $data = $this->client->graphql($this->inventoryItemTrackingMutation(), [
            'inventoryItemId' => $inventoryItemId,
            'tracked' => $tracked,
        ]);

        $errors = data_get($data, 'inventoryItemUpdate.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            throw new \RuntimeException($this->formatUserErrors($errors, 'inventoryItemUpdate'));
        }
    }

    private function updateShopifyOnHandQuantity(string $inventoryItemId, int $quantity, string $variantId): void
    {
        $locationId = $this->firstLocationId();
        if ($locationId === null) {
            throw new \RuntimeException('No Shopify location found for inventory update.');
        }

        $data = $this->client->graphql($this->inventorySetMutation(), [
            'input' => [
                'name' => 'on_hand',
                'reason' => 'correction',
                'ignoreCompareQuantity' => true,
                'referenceDocumentUri' => 'logistics://shopify-editor/inventory/' . rawurlencode($variantId),
                'quantities' => [[
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'quantity' => $quantity,
                ]],
            ],
        ]);

        $errors = data_get($data, 'inventorySetQuantities.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            throw new \RuntimeException($this->formatUserErrors($errors, 'inventorySetQuantities'));
        }
    }

    private function updateShopifyProductStatus(string $productId, string $status): void
    {
        $mappedStatus = match (strtolower(trim($status))) {
            'active' => 'ACTIVE',
            'archived' => 'ARCHIVED',
            default => 'DRAFT',
        };

        $data = $this->client->graphql($this->productStatusMutation(), [
            'input' => [
                'id' => $productId,
                'status' => $mappedStatus,
            ],
        ]);

        $errors = data_get($data, 'productUpdate.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            throw new \RuntimeException($this->formatUserErrors($errors, 'productUpdate'));
        }
    }

    /**
     * @param array<int, string> $warnings
     */
    private function refreshComplementaryParents(Product $product, ?int $userId, array &$warnings): void
    {
        $parents = $this->dependencyService->productsReferencingProduct($product);
        if ($parents->isEmpty()) {
            return;
        }

        $result = $this->shopifyUpdater->syncComplementaryProducts($parents, $userId);

        foreach ($result['warnings'] ?? [] as $warning) {
            if (is_array($warning) && isset($warning['warning'])) {
                $warnings[] = (string) $warning['warning'];
            }
        }

        foreach ($result['failures'] ?? [] as $failure) {
            if (is_array($failure) && isset($failure['details'])) {
                $warnings[] = (string) $failure['details'];
            }
        }
    }

    private function markInventorySynced(Variant $variant, ?string $syncBatchId): void
    {
        Variant::withoutEvents(function () use ($variant, $syncBatchId): void {
            $variant->forceFill([
                'inventory_local_dirty' => false,
                'inventory_sync_error' => null,
                'inventory_pushed_at' => now(),
                'inventory_sync_batch_id' => $syncBatchId,
            ])->save();
        });
    }

    private function markInventorySyncFailed(Variant $variant, string $message): void
    {
        Variant::withoutEvents(function () use ($variant, $message): void {
            $variant->forceFill([
                'inventory_sync_error' => $message,
                'inventory_local_dirty' => true,
            ])->save();
        });
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function applyRemoteVariantRefresh(Variant $variant, array $updates, ?int $userId = null, ?Product $product = null): void
    {
        $changes = [];
        foreach ($updates as $field => $value) {
            $original = $variant->getAttribute($field);
            if ($original !== $value) {
                $changes[$field] = [
                    'old' => $original,
                    'new' => $value,
                ];
            }
        }

        Variant::withoutEvents(function () use ($variant, $updates): void {
            $variant->forceFill($updates)->save();
        });

        if ($changes === []) {
            return;
        }

        $product ??= Product::find($variant->product_id);
        foreach ($changes as $field => $change) {
            ChangeLog::create([
                'import_id' => $product?->import_id,
                'product_id' => $product?->id,
                'changed_by' => $userId,
                'model_type' => Variant::class,
                'model_id' => $variant->id,
                'field' => $field,
                'old_value' => $this->stringifyValue($change['old']),
                'new_value' => $this->stringifyValue($change['new']),
            ]);
        }
    }

    private function normalizeRemoteInventoryQuantity(mixed $value): ?int
    {
        if ($value === null || !is_numeric((string) $value)) {
            return null;
        }

        return (int) $value;
    }

    /** @return array<string,int> */
    private function inventoryQuantities(array $remoteVariant): array
    {
        $totals = [];
        foreach ((array) data_get($remoteVariant, 'inventoryItem.inventoryLevels.nodes', []) as $level) {
            foreach ((array) data_get($level, 'quantities', []) as $quantity) {
                $name = trim((string) ($quantity['name'] ?? ''));
                $value = $this->normalizeRemoteInventoryQuantity($quantity['quantity'] ?? null);
                if ($name !== '' && $value !== null) {
                    $totals[$name] = ($totals[$name] ?? 0) + $value;
                }
            }
        }

        return $totals;
    }

    private function inventoryLocationCount(array $remoteVariant): int
    {
        return collect((array) data_get($remoteVariant, 'inventoryItem.inventoryLevels.nodes', []))
            ->pluck('location.id')->filter()->unique()->count();
    }

    private function normalizeRemoteMoney(mixed $value): ?string
    {
        if ($value === null || !is_numeric((string) $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeShopifyInventoryItemId(mixed $value): ?string
    {
        $id = trim((string) ($value ?? ''));

        return $id !== '' ? $id : null;
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    private function firstLocationId(): ?string
    {
        $data = $this->client->graphql($this->locationsQuery(), []);

        $id = trim((string) data_get($data, 'locations.nodes.0.id', ''));

        return $id !== '' ? $id : null;
    }

    /**
     * @param array<int, array{field?:array|string|null,message?:string|null}> $errors
     */
    private function formatUserErrors(array $errors, string $fallbackField = 'input'): string
    {
        return collect($errors)
            ->map(function (array $error) use ($fallbackField): string {
                $field = $error['field'] ?? null;
                $fieldPath = $fallbackField;
                if (is_array($field)) {
                    $fieldPath = implode('.', $field);
                } elseif (is_string($field) && $field !== '') {
                    $fieldPath = $field;
                }

                return $fieldPath . ': ' . ($error['message'] ?? 'Unknown error');
            })
            ->filter()
            ->implode('; ');
    }

    private function productInventoryByIdQuery(): string
    {
        return <<<'GQL'
query ProductInventoryById($id: ID!) {
  product(id: $id) {
    id
    status
    createdAt
    variants(first: 250) {
      nodes {
        id
        sku
        availableForSale
        price
        compareAtPrice
        inventoryQuantity
        inventoryItem {
          id
          tracked
          inventoryLevels(first: 250) {
            nodes {
              location { id }
              quantities(names: ["available", "committed", "on_hand"]) { name quantity }
            }
          }
        }
      }
    }
  }
}
GQL;
    }

    private function productInventoryByHandleQuery(): string
    {
        return <<<'GQL'
query ProductInventoryByHandle($handle: String!) {
  productByHandle(handle: $handle) {
    id
    status
    createdAt
    variants(first: 250) {
      nodes {
        id
        sku
        availableForSale
        price
        compareAtPrice
        inventoryQuantity
        inventoryItem {
          id
          tracked
          inventoryLevels(first: 250) {
            nodes {
              location { id }
              quantities(names: ["available", "committed", "on_hand"]) { name quantity }
            }
          }
        }
      }
    }
  }
}
GQL;
    }

    private function productStatusMutation(): string
    {
        return <<<'GQL'
mutation ProductStatusUpdate($input: ProductInput!) {
  productUpdate(input: $input) {
    product {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function inventoryItemTrackingMutation(): string
    {
        return <<<'GQL'
mutation InventoryItemTrackingUpdate($inventoryItemId: ID!, $tracked: Boolean!) {
  inventoryItemUpdate(
    id: $inventoryItemId,
    input: {
      tracked: $tracked
    }
  ) {
    inventoryItem {
      id
      tracked
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function inventorySetMutation(): string
    {
        return <<<'GQL'
mutation InventorySetQuantities($input: InventorySetQuantitiesInput!) {
  inventorySetQuantities(input: $input) {
    userErrors { field message code }
  }
}
GQL;
    }

    private function locationsQuery(): string
    {
        return <<<'GQL'
query Locations {
  locations(first: 1) {
    nodes { id }
  }
}
GQL;
    }
}
