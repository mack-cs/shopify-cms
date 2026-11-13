<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\InventoryAdjustmentRequestItem;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use App\Services\Inventory\InventoryAuditContext;
use App\Services\Inventory\InventoryAuditRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        if (str_starts_with((string) $syncBatchId, 'inventory_approval_')) {
            app(InventoryAdjustmentApprovalService::class)->authorizeBatch($syncBatchId, $variants->pluck('id')->all(), $userId);
        } elseif (str_starts_with((string) $syncBatchId, 'stack_sellability_')) {
            abort_if($variants->contains(fn ($v) => ! $v->product?->is_bundle), 403, 'Automatic sellability only authorizes stacks.');
        } else {
            app(InventoryAccessService::class)->authorizeInventory($userId);
            abort_if(InventoryAdjustmentRequestItem::whereIn('variant_id',$variants->pluck('id'))
                ->whereHas('request',fn($q)=>$q->whereIn('status',[\App\Models\InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL,\App\Models\InventoryAdjustmentRequest::STATUS_APPROVED]))->exists(),403,'Use the approved inventory request job for these items.');
        }
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
                $permanentFailures = [];
                $this->syncProductVariants($product, $group->values(), $syncBatchId, $userId, $warnings, $permanentFailures);
                foreach ($permanentFailures as $failure) {
                    $failed++;
                    $failures[] = $failure;
                }
                $synced += $group->count() - count($permanentFailures);
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
                $this->preserveInventoryAfterIncompleteRead($group, $e);
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
            $this->preserveInventoryAfterIncompleteRead($variants, $e);

            return [
                'synced' => 0,
                'refreshed' => 0,
                'failed' => max(1, $variants->count()),
                'warnings' => [],
                'failures' => ["Product {$product->id}: {$e->getMessage()}"],
            ];
        }
    }

    /** Shopify query only. Used to adopt current remote stock when discarding stale local work. */
    public function readForPushCancellation(Variant $variant): array
    {
        $variant->loadMissing('product');
        if (! $variant->product || ! $variant->shopify_inventory_item_id) {
            throw new \RuntimeException('A synchronized product and inventory item are required to read Shopify.');
        }
        $details = $this->shopifyProductInventoryDetails($variant->product);
        $this->assertInventoryRefreshComplete($details, collect([$variant]));
        $remote = $this->matchRemoteVariant($variant, collect(data_get($details, 'variants.nodes', [])));
        if (data_get($details, 'variants.pageInfo.hasNextPage') !== false
            || data_get($remote, 'inventoryItem.inventoryLevels.pageInfo.hasNextPage') !== false
            || ! is_bool(data_get($remote, 'inventoryItem.tracked'))
            || ! in_array(strtolower((string) ($remote['inventoryPolicy'] ?? '')), ['deny', 'continue'], true)) {
            throw new \RuntimeException('Shopify did not return complete pagination/tracking/policy information. Nothing was discarded.');
        }
        if (($remote['id'] ?? null) !== $variant->shopify_id
            || data_get($remote, 'inventoryItem.id') !== $variant->shopify_inventory_item_id) {
            throw new \RuntimeException('Shopify inventory identity differs from the selected SKU. Nothing was discarded.');
        }
        $levels = (array) data_get($remote, 'inventoryItem.inventoryLevels.nodes', []);
        if (data_get($remote, 'inventoryItem.tracked') === true) {
            if ($levels === []) {
                throw new \RuntimeException('Shopify returned no inventory locations. Nothing was discarded.');
            }
            foreach ($levels as $level) {
                $states = collect($level['quantities'] ?? [])->pluck('quantity', 'name');
                if (! data_get($level, 'location.id') || ! $states->has(['available', 'committed', 'on_hand'])
                    || $states->contains(fn ($value) => ! is_numeric($value))) {
                    throw new \RuntimeException('Shopify returned incomplete inventory quantities. Nothing was discarded.');
                }
            }
        }
        $tracked = data_get($remote, 'inventoryItem.tracked');
        $quantities = $this->inventoryQuantities($remote);

        return ['observed_at' => now()->toIso8601String(), 'inventory_item_id' => $variant->shopify_inventory_item_id,
            'locations' => $levels, 'updates' => [
                'inventory_tracked' => $tracked, 'inventory_qty' => $tracked ? ($quantities['available'] ?? null) : null,
                'current_inventory_quantity' => $tracked ? ($quantities['available'] ?? null) : null,
                'current_available_quantity' => $tracked ? ($quantities['available'] ?? null) : null,
                'current_on_hand_quantity' => $tracked ? ($quantities['on_hand'] ?? null) : null,
                'current_committed_quantity' => $tracked ? ($quantities['committed'] ?? null) : null,
                'inventory_location_count' => $this->inventoryLocationCount($remote),
                'inventory_policy' => strtolower((string) ($remote['inventoryPolicy'] ?? 'deny')),
                'shopify_available_for_sale' => $remote['availableForSale'] ?? null,
            ]];
    }

    /**
     * @param Collection<int, Variant> $variants
     * @param array<int, string> $warnings
     * @param array<int, string> $permanentFailures
     */
    private function syncProductVariants(Product $product, Collection $variants, ?string $syncBatchId, ?int $userId, array &$warnings, array &$permanentFailures): void
    {
        $replayBlocked = 'An inventory mutation has an uncertain outcome. Operator reconciliation is required; automatic replay is blocked.';
        $variants = $variants->filter(function ($variant) use (&$permanentFailures, $replayBlocked): bool {
            if (! $variant instanceof Variant || ! app(InventoryWriteIntents::class)->uncertainOutcome($variant)) {
                return $variant instanceof Variant;
            }
            $this->markInventorySyncFailed($variant, $replayBlocked);
            $permanentFailures[] = "Variant {$variant->id}: {$replayBlocked}";

            return false;
        })->values();
        if ($variants->isEmpty()) {
            return;
        }

        app(\App\Services\EngravedBeads\EngravedBeadMutationGuard::class)->graphql('mutation InventoryPreflight',
            ['productId' => $product->shopify_id, 'variants' => $variants->map(fn ($variant) => [
                'id' => $variant->shopify_id, 'inventoryItemId' => $variant->shopify_inventory_item_id,
            ])->all()]);
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
            ->filter(fn (Variant $variant): bool => $this->shouldPushVariantInventory($variant, $syncBatchId))
            ->values();
        if (str_starts_with((string) $syncBatchId, 'inventory_approval_')
            && $variantsToSync->count() !== $variants->filter(fn ($variant): bool => $variant instanceof Variant)->count()) {
            throw new \RuntimeException('Approved inventory correction was not published. Shopify was not updated.');
        }

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

            $this->writeConfirmedInventory($product, $variant, $remoteVariant, $syncBatchId, $userId);
            $this->markInventorySynced($variant, $syncBatchId);
        }

        $this->refreshComplementaryParents($product, $userId, $warnings);
        $this->refreshProductVariants($product->fresh(), $variants, $userId);
    }

    private function writeConfirmedInventory(Product $product, Variant $variant, array $remote, ?string $batch, ?int $actor): void
    {
        $item = $batch === null ? null : InventoryAdjustmentRequestItem::where('variant_id', $variant->id)
            ->whereHas('request', fn ($q) => $q->where('sync_batch_id', $batch))->first();
        if ($item && (int) data_get($item->shopify_update_result,'write_version',-1) !== (int) $variant->inventory_write_version) {
            throw new \RuntimeException('A newer inventory intent superseded this approval. Review before dispatching stock.');
        }
        $tracked = $item ? $item->requested_inventory_tracked : $variant->inventory_tracked;
        if ($tracked === null) throw new \RuntimeException('Unknown inventory tracking cannot authorize a write.');
        $target = $item ? $item->requested_on_hand_quantity
            : ($product->is_bundle ? $variant->inventory_qty : ($variant->current_on_hand_quantity ?? $variant->inventory_qty));
        if ($tracked && $target === null) throw new \RuntimeException('Unknown on-hand quantity cannot authorize a write.');
        if ($tracked && $this->inventoryLocationCount($remote) > 1) {
            throw new \RuntimeException('This physical count covers multiple inventory locations. A location-specific review is required; no aggregate quantity was written.');
        }
        $location = $this->firstLocationId();
        $payload = ['tracked' => $tracked, 'target' => $target, 'location' => $location];
        if (\App\Models\InventoryWriteIntent::where('intent_key',app(InventoryWriteIntents::class)->key($variant,$payload,$batch))->where('status','confirmed')->exists()) {
            app(InventoryWriteIntents::class)->claim($variant,$payload,$batch);
            if (data_get($remote, 'inventoryItem.tracked') !== $tracked
                || ($tracked && $this->locationOnHand($remote, $location) !== (int) $target)) {
                throw new \RuntimeException('Shopify has not confirmed the intended inventory state. Reconciliation is required.');
            }
            return;
        }
        $expected = $this->locationOnHand($remote, $location);
        if ($item && $item->original_inventory_tracked && $item->original_on_hand_quantity !== $expected) {
            throw new \RuntimeException('Shopify stock changed since this count was requested. Review a new adjustment; no write was sent.');
        }
        $intent = app(InventoryWriteIntents::class)->claim($variant, $payload, $batch);
        if ($intent->status === 'confirmed') return;
        $authorize = function () use ($batch, $variant, $actor): void {
            if ((int) Variant::whereKey($variant->id)->value('inventory_write_version') !== (int) $variant->inventory_write_version) {
                throw new \RuntimeException('Inventory intent changed while Shopify was being read. No new mutation is authorized.');
            }
            if (str_starts_with((string) $batch, 'inventory_approval_')) {
                app(InventoryAdjustmentApprovalService::class)->authorizeBatch($batch,[$variant->id],$actor);
            } elseif (! str_starts_with((string) $batch, 'stack_sellability_')) {
                app(InventoryAccessService::class)->authorizeInventory($actor);
            }
        };
        $mutationStarted = false;
        try {
            $inventoryItem = (string) data_get($remote, 'inventoryItem.id');
            if (data_get($remote, 'inventoryItem.tracked') !== $tracked) {
                $authorize();
                $mutationStarted = true;
                $this->updateShopifyInventoryTracking($inventoryItem, $tracked);
                if ($tracked) {
                    $remote = $this->matchRemoteVariant($variant, collect(data_get($this->shopifyProductInventoryDetails($product), 'variants.nodes', []))) ?? [];
                    $expected = $this->locationOnHand($remote, $location);
                }
            }
            $authorize();
            if (in_array($variant->inventory_policy,['deny','continue'],true)) $mutationStarted = true;
            $this->updateShopifyInventoryPolicy($product->shopify_id, (string) ($remote['id'] ?? ''), $variant->inventory_policy);
            if ($tracked) {
                $previouslyUntracked = $item && $item->original_inventory_tracked === false;
                if ($expected === null && ! $previouslyUntracked) {
                    throw new \RuntimeException('Shopify on-hand comparison quantity is unavailable.');
                }
                $authorize();
                $mutationStarted = true;
                $this->updateShopifyOnHandQuantity($inventoryItem, (int) $target, (string) ($remote['id'] ?? ''), $variant, $actor, $batch, $expected, $intent->intent_key);
            }
            $confirmed = $this->matchRemoteVariant($variant, collect(data_get($this->shopifyProductInventoryDetails($product), 'variants.nodes', [])));
            if ($confirmed === null || data_get($confirmed, 'inventoryItem.tracked') !== $tracked
                || ($tracked && $this->locationOnHand($confirmed, $location) !== (int) $target)) {
                throw new \RuntimeException('Shopify has not confirmed the intended inventory state. Reconciliation is required.');
            }
            $intent->update(['status' => 'confirmed']);
        } catch (\Throwable $e) {
            // Explicit Shopify rejection is a known non-application. Transport/malformed
            // responses and divergent readback remain started and must never be replayed.
            $intent->update(['status' => ! $mutationStarted || $e instanceof \App\Exceptions\InventoryMutationRejectedException ? 'rejected' : 'started', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function locationOnHand(array $remote, ?string $location): ?int
    {
        foreach ((array) data_get($remote, 'inventoryItem.inventoryLevels.nodes', []) as $level) {
            if (data_get($level, 'location.id') !== $location) continue;
            foreach ($level['quantities'] ?? [] as $quantity) {
                if (($quantity['name'] ?? '') === 'on_hand') return $this->normalizeRemoteInventoryQuantity($quantity['quantity'] ?? null);
            }
        }
        return null;
    }

    private function shouldPushVariantInventory(Variant $variant, ?string $syncBatchId = null): bool
    {
        if ($this->approvedCorrectionPending($variant, $syncBatchId)) {
            return true;
        }

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

    private function approvedCorrectionPending(Variant $variant, ?string $syncBatchId): bool
    {
        if (! str_starts_with((string) $syncBatchId, 'inventory_approval_')) {
            return false;
        }

        return InventoryAdjustmentRequestItem::query()
            ->where('variant_id', $variant->id)
            ->whereHas('request', fn ($query) => $query
                ->where('sync_batch_id', $syncBatchId)
                ->where('status', \App\Models\InventoryAdjustmentRequest::STATUS_APPROVED))
            ->exists();
    }

    private function approvedCorrectionAwaitingConfirmation(Variant $variant): bool
    {
        $items = InventoryAdjustmentRequestItem::query()
            ->where('variant_id', $variant->id)
            ->whereHas('request', fn ($query) => $query->where('status', \App\Models\InventoryAdjustmentRequest::STATUS_APPROVED))
            ->get();

        foreach ($items as $item) {
            $confirmed = \App\Models\InventoryWriteIntent::query()
                ->where('variant_id', $variant->id)
                ->where('status', 'confirmed')
                ->get()
                ->contains(function (\App\Models\InventoryWriteIntent $intent) use ($item): bool {
                    $payload = is_array($intent->payload) ? $intent->payload : [];
                    if ((bool) ($payload['tracked'] ?? false) !== (bool) $item->requested_inventory_tracked) {
                        return false;
                    }
                    if ($item->requested_inventory_tracked !== true) {
                        return true;
                    }

                    return (int) ($payload['target'] ?? -1) === (int) $item->requested_on_hand_quantity;
                });
            if (! $confirmed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Collection<int, Variant> $variants
     */
    private function assertInventoryRefreshComplete(array $details, Collection $variants): void
    {
        if (! is_array(data_get($details, 'variants'))) {
            throw new \RuntimeException('Incomplete Shopify inventory read: product inventory payload is missing.');
        }
        if (data_get($details, 'variants.pageInfo.hasNextPage') === true) {
            throw new \RuntimeException('Incomplete Shopify inventory read: variant pagination is incomplete.');
        }

        $remoteVariants = collect(data_get($details, 'variants.nodes', []))
            ->filter(fn ($node): bool => is_array($node))
            ->values();
        foreach ($variants as $variant) {
            if (! $variant instanceof Variant) {
                continue;
            }
            $this->assertRemoteInventoryReadComplete($variant, $this->matchRemoteVariant($variant, $remoteVariants));
        }
    }

    private function assertRemoteInventoryReadComplete(Variant $variant, ?array $remote): void
    {
        if (! is_array($remote)) {
            throw new \RuntimeException("Incomplete Shopify inventory read: expected variant {$variant->sku} was not returned.");
        }
        $item = data_get($remote, 'inventoryItem');
        if (! is_array($item) || trim((string) ($item['id'] ?? '')) === '') {
            throw new \RuntimeException("Incomplete Shopify inventory read: inventory item for {$variant->sku} is missing.");
        }
        if (data_get($item, 'inventoryLevels.pageInfo.hasNextPage') === true) {
            throw new \RuntimeException('Incomplete Shopify inventory read: inventory level pagination is incomplete.');
        }
        if (! array_key_exists('tracked', $item) || $item['tracked'] === null) {
            throw new \RuntimeException("Incomplete Shopify inventory read: tracking status for {$variant->sku} is missing.");
        }
        if ($item['tracked'] === true) {
            $available = $this->inventoryQuantities($remote)['available']
                ?? $this->normalizeRemoteInventoryQuantity(data_get($remote, 'inventoryQuantity'));
            if ($available === null) {
                throw new \RuntimeException("Incomplete Shopify inventory read: stock quantity for {$variant->sku} is missing.");
            }
        }
    }

    /**
     * @param Collection<int, mixed> $variants
     */
    private function preserveInventoryAfterIncompleteRead(Collection $variants, \Throwable $exception): void
    {
        $message = $exception->getMessage();
        if (! str_starts_with($message, 'Incomplete Shopify inventory read')) {
            $message = 'Incomplete Shopify inventory read: '.$message;
        }

        foreach ($variants as $variant) {
            if (! $variant instanceof Variant) {
                continue;
            }
            if ($variant->shopify_inventory_item_id) {
                app(InventoryLevelReconciliation::class)->record((string) $variant->shopify_inventory_item_id);
            }
            Variant::withoutEvents(function () use ($variant, $message): void {
                $variant->forceFill(['inventory_sync_error' => $message])->save();
            });
        }
    }

    /**
     * @param Collection<int, Variant> $variants
     */
    private function refreshProductVariants(Product $product, Collection $variants, ?int $userId): void
    {
        $details = $this->shopifyProductInventoryDetails($product);
        $this->assertInventoryRefreshComplete($details, $variants);
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
                    throw new \RuntimeException("Incomplete Shopify inventory read: expected variant {$variant->sku} was not returned.");
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
                    'inventory_policy' => strtolower((string) data_get($remoteVariant, 'inventoryPolicy')) === 'continue' ? 'continue' : 'deny',
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

        $inventoryItemId = trim((string) ($variant->shopify_inventory_item_id ?? ''));
        if ($inventoryItemId !== '') {
            $matches = $remoteVariants
                ->filter(fn (array $node): bool => trim((string) data_get($node, 'inventoryItem.id', '')) === $inventoryItemId)
                ->values();
            if ($matches->count() === 1 && is_array($matches->first())) {
                return $matches->first();
            }
        }

        $sku = trim((string) ($variant->sku ?? ''));
        if ($sku !== '') {
            $matches = $remoteVariants
                ->filter(fn (array $node): bool => trim((string) ($node['sku'] ?? '')) === $sku)
                ->values();
            if ($matches->count() === 1 && is_array($matches->first())) {
                return $matches->first();
            }
        }

        return null;
    }

    private function updateShopifyInventoryTracking(string $inventoryItemId, ?bool $tracked): void
    {
        if ($tracked === null) {
            return;
        }

        logger()->info('Shopify inventory write executed', [
            'origin' => 'inventory',
            'inventory_item_id' => $inventoryItemId,
            'mutation' => 'inventoryItemUpdate',
            'fields' => ['tracked'],
        ]);

        $data = $this->client->graphql($this->inventoryItemTrackingMutation(), [
            'inventoryItemId' => $inventoryItemId,
            'tracked' => $tracked,
        ]);

        $errors = data_get($data, 'inventoryItemUpdate.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            throw new \App\Exceptions\InventoryMutationRejectedException($this->formatUserErrors($errors, 'inventoryItemUpdate'));
        }
        if (data_get($data, 'inventoryItemUpdate.inventoryItem.id') !== $inventoryItemId
            || data_get($data, 'inventoryItemUpdate.inventoryItem.tracked') !== $tracked) {
            throw new \RuntimeException('Shopify did not confirm inventory tracking.');
        }
    }

    private function updateShopifyInventoryPolicy(string $productId, string $variantId, ?string $policy): void
    {
        $productId = trim($productId);
        $variantId = trim($variantId);
        $policy = strtolower(trim((string) $policy));

        if ($productId === '' || $variantId === '' || ! in_array($policy, ['continue', 'deny'], true)) {
            return;
        }

        $data = $this->client->graphql($this->variantInventoryPolicyMutation(), [
            'productId' => $productId,
            'variants' => [[
                'id' => $variantId,
                'inventoryPolicy' => strtoupper($policy),
            ]],
        ]);
        $errors = data_get($data, 'productVariantsBulkUpdate.userErrors', []);
        if (is_array($errors) && ! empty($errors)) {
            throw new \RuntimeException($this->formatUserErrors($errors, 'productVariantsBulkUpdate'));
        }
    }

    private function updateShopifyOnHandQuantity(string $inventoryItemId, int $quantity, string $variantId, ?Variant $variant = null, ?int $userId = null, ?string $syncBatchId = null, ?int $expected = null, ?string $idempotencyKey = null): void
    {
        $locationId = $this->firstLocationId();
        if ($locationId === null) {
            throw new \RuntimeException('No Shopify location found for inventory update.');
        }

        logger()->info('Shopify inventory write executed', [
            'origin' => 'inventory',
            'inventory_item_id' => $inventoryItemId,
            'variant_id' => $variantId,
            'mutation' => 'inventorySetQuantities',
            'fields' => ['on_hand'],
        ]);

        $reason = null;
        if ($syncBatchId !== null && $variant instanceof Variant) {
            $reason = InventoryAdjustmentRequestItem::query()
                ->where('variant_id', $variant->id)
                ->whereHas('request', fn ($query) => $query->where('sync_batch_id', $syncBatchId))
                ->value('reason');
        }
        $context = new InventoryAuditContext(
            correlationKey: $syncBatchId !== null && $variant instanceof Variant
                ? 'inventory-set:'.$syncBatchId.':'.$variant->id
                : 'inventory-set:'.($variant?->id ?? $variantId).':'.Str::uuid(),
            workflow: 'inventory_correction',
            action: 'inventory_correction',
            actorType: $userId ? 'user' : 'system',
            execution: 'queue',
            userId: $userId,
            processName: 'Inventory sync job',
            explanation: is_string($reason) && $reason !== '' ? $reason : 'Absolute on-hand quantity sent to Shopify.',
            shopifyReason: 'correction',
            inventoryState: 'on_hand',
            quantityAfter: $quantity,
            productId: $variant?->product_id ? (int) $variant->product_id : null,
            variantId: $variant?->id ? (int) $variant->id : null,
            sku: $variant?->sku,
            inventoryItemId: $inventoryItemId,
            locationId: $locationId,
            referenceDocumentUri: 'logistics://shopify-editor/inventory/'.rawurlencode($variantId),
        );

        try {
            $data = $this->client->graphql($this->inventorySetMutation(), [
                'input' => [
                    'name' => 'on_hand',
                    'reason' => 'correction',
                    'referenceDocumentUri' => 'logistics://shopify-editor/inventory/' . rawurlencode($variantId),
                    'quantities' => [[
                        'inventoryItemId' => $inventoryItemId,
                        'locationId' => $locationId,
                        'quantity' => $quantity,
                        ...($expected === null ? [] : ['changeFromQuantity' => $expected]),
                    ]],
                ],
                'idempotencyKey' => $idempotencyKey,
            ]);
        } catch (\Throwable $exception) {
            app(InventoryAuditRecorder::class)->failed($context, $exception->getMessage());

            throw $exception;
        }

        $errors = data_get($data, 'inventorySetQuantities.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            $message = $this->formatUserErrors($errors, 'inventorySetQuantities');
            app(InventoryAuditRecorder::class)->failed($context, $message);

            throw new \App\Exceptions\InventoryMutationRejectedException($message);
        }

        if (! is_array(data_get($data, 'inventorySetQuantities.inventoryAdjustmentGroup'))
            || ! data_get($data, 'inventorySetQuantities.inventoryAdjustmentGroup.createdAt')) {
            $message = 'Shopify did not confirm the inventory adjustment; automatic replay is blocked.';
            app(InventoryAuditRecorder::class)->failed($context,$message);
            throw new \RuntimeException($message);
        }

        app(InventoryAuditRecorder::class)->succeeded($context, null);
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
        \Illuminate\Support\Facades\DB::transaction(function () use ($variant, $syncBatchId): void {
            $locked = Variant::lockForUpdate()->findOrFail($variant->id);
            if ((int) $locked->inventory_write_version !== (int) $variant->inventory_write_version) {
                throw new \RuntimeException('A newer inventory intent was retained after the previous Shopify operation.');
            }
            $locked->forceFill([
                'inventory_local_dirty' => false,
                'inventory_sync_error' => null,
                'inventory_pushed_at' => now(),
                'inventory_sync_batch_id' => $syncBatchId,
            ])->saveQuietly();
        });
        $variant->refresh();
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
        \Illuminate\Support\Facades\DB::transaction(function () use ($variant, $updates, $userId, $product): void {
        $latest = Variant::lockForUpdate()->findOrFail($variant->id);
        if ((int) $latest->inventory_write_version !== (int) $variant->inventory_write_version) {
            unset($updates['inventory_tracked'],$updates['inventory_qty'],$updates['current_on_hand_quantity'],$updates['inventory_policy']);
            $updates['inventory_local_dirty'] = $latest->inventory_local_dirty;
            $updates['inventory_sync_error'] = $latest->inventory_sync_error;
        }
        $variant->setRawAttributes($latest->getAttributes(),true);
        if (\App\Models\InventoryWriteIntent::where('variant_id',$variant->id)->where('status','started')->exists()) {
            $updates['inventory_local_dirty'] = true;
            $updates['inventory_sync_error'] = 'An inventory mutation has an uncertain outcome. Operator reconciliation is required.';
            unset($updates['inventory_tracked'],$updates['inventory_qty'],$updates['current_on_hand_quantity']);
        }
        if ($this->approvedCorrectionAwaitingConfirmation($variant)) {
            $updates['inventory_local_dirty'] = true;
        }
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
        });
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
        $configured = trim((string) config('services.shopify.inventory_location_id'));
        if ($configured !== '') {
            return $configured;
        }

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
      pageInfo { hasNextPage }
      nodes {
        id
        sku
        availableForSale
        price
        compareAtPrice
        inventoryQuantity
        inventoryPolicy
        inventoryItem {
          id
          tracked
          inventoryLevels(first: 250) {
            pageInfo { hasNextPage }
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
      pageInfo { hasNextPage }
      nodes {
        id
        sku
        availableForSale
        price
        compareAtPrice
        inventoryQuantity
        inventoryPolicy
        inventoryItem {
          id
          tracked
          inventoryLevels(first: 250) {
            pageInfo { hasNextPage }
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
mutation InventorySetQuantities($input: InventorySetQuantitiesInput!, $idempotencyKey: String!) {
  inventorySetQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup { createdAt reason referenceDocumentUri }
    userErrors { field message code }
  }
}
GQL;
    }

    private function variantInventoryPolicyMutation(): string
    {
        return <<<'GQL'
mutation VariantInventoryPolicyUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id inventoryPolicy }
    userErrors { field message }
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
