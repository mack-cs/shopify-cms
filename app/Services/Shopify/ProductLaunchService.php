<?php

namespace App\Services\Shopify;

use App\Jobs\LaunchProductsOnShopifyJob;
use App\Models\ChangeLog;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryAdjustmentRequestItem;
use App\Models\Product;
use App\Models\ProductLaunchItem;
use App\Models\ProductLaunchRun;
use App\Models\User;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadConfiguration;
use App\Services\EngravedBeads\EngravedBeadStockPool;
use App\Services\EngravedBeads\EngravedBeadWriteGuard;
use App\Services\Inventory\InventoryAuditContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ProductLaunchService
{
    public function __construct(
        private readonly ProductLaunchShopify $shopify,
        private readonly ShopifyInventoryAdjustmentService $inventory,
    ) {}

    /** @param Collection<int, Product> $products @param array<int, string> $publicationIds */
    public function queue(Collection $products, array $publicationIds, ?int $userId): ProductLaunchRun
    {
        $this->authorizeActor($userId);
        if (! (bool) config('product_launch.enabled', true)) {
            throw new \RuntimeException('Product launch is disabled.');
        }
        $publicationIds = array_values(array_unique(array_filter(array_map(
            fn (string $id): string => trim($id),
            $publicationIds,
        ))));
        if ($publicationIds === []) {
            throw new \RuntimeException('Choose at least one Shopify sales channel.');
        }
        if (array_diff($publicationIds, (array) config('product_launch.publications', [])) !== []) {
            throw new \InvalidArgumentException('Only configured Shopify publications may be selected.');
        }

        $run = ProductLaunchRun::query()->create([
            'user_id' => $userId,
            'status' => ProductLaunchRun::STATUS_QUEUED,
            'publication_ids' => $publicationIds,
        ]);
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $variant = $product->variants()->first();
            ProductLaunchItem::query()->create([
                'product_launch_run_id' => $run->id,
                'product_id' => $product->id,
                'product_title' => $product->title,
                'sku' => $variant?->sku,
                'outcome' => ProductLaunchItem::OUTCOME_PENDING,
                'validation' => ProductLaunchItem::VALIDATION_READY,
                'publication_ids' => $publicationIds,
                'inventory_idempotency_key' => (string) Str::uuid(),
            ]);
        }

        LaunchProductsOnShopifyJob::dispatch($run->id);

        return $run;
    }

    public function run(ProductLaunchRun $run): ProductLaunchRun
    {
        $this->assertEnabled($run);
        $run->forceFill([
            'status' => ProductLaunchRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'error_message' => null,
        ])->save();

        $failed = false;
        foreach ($run->items()->with('product.variants')->orderBy('id')->get() as $item) {
            if (in_array($item->outcome, [ProductLaunchItem::OUTCOME_LAUNCHED, ProductLaunchItem::OUTCOME_SKIPPED, ProductLaunchItem::OUTCOME_BLOCKED], true)) {
                $failed = $failed || $item->outcome === ProductLaunchItem::OUTCOME_BLOCKED;

                continue;
            }
            try {
                Cache::lock('product-launch:'.$item->product_id, 960)
                    ->block(5, fn () => $this->launchItem($run, $item));
            } catch (\Throwable $exception) {
                $failed = true;
                $item->forceFill([
                    'outcome' => ProductLaunchItem::OUTCOME_FAILED,
                    'validation' => ProductLaunchItem::VALIDATION_BLOCKED,
                    'detail' => $this->failureDetail($item, $exception->getMessage()),
                ])->save();
            }
            $failed = $failed || in_array($item->fresh()->outcome, [
                ProductLaunchItem::OUTCOME_FAILED,
                ProductLaunchItem::OUTCOME_BLOCKED,
            ], true);
        }

        $run->forceFill([
            'status' => $failed
                ? ProductLaunchRun::STATUS_COMPLETED_WITH_FAILURES
                : ProductLaunchRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ])->save();

        return $run->fresh(['items']) ?? $run;
    }

    public function retry(ProductLaunchRun $run, int $actorId): void
    {
        $this->authorizeActor($actorId);
        $this->assertEnabled($run);
        if (! $run->isFinished()) {
            throw new \RuntimeException('Wait for the running launch to finish before retrying.');
        }
        $run->update(['status' => ProductLaunchRun::STATUS_QUEUED, 'finished_at' => null, 'error_message' => null]);
        LaunchProductsOnShopifyJob::dispatch($run->id);
    }

    private function launchItem(ProductLaunchRun $run, ProductLaunchItem $item): void
    {
        $this->assertEnabled($run);
        $product = $item->product;
        if (! $product instanceof Product) {
            $this->block($item, 'The product no longer exists.');

            return;
        }
        $product->loadMissing('variants');
        $stack = $product->isStackProduct();
        $variant = $product->variants->count() === 1 ? $product->variants->first() : null;
        $blocker = $this->blocker($product, $variant, $stack);
        if ($blocker !== null) {
            $this->block($item, $blocker, $product, $variant, $stack);

            return;
        }

        $remote = $this->shopify->read($product);
        $selected = array_values(array_filter((array) $run->publication_ids));
        $missingPublications = array_values(array_diff($selected, $remote['published_ids']));
        $inventoryPlan = $stack
            ? ['action' => 'skip', 'current' => null, 'proposed' => null, 'warning' => 'Stacks are not inventory-tracked and no stock quantity was assigned.']
            : $this->inventoryPlan($variant, $item);

        if ($inventoryPlan['action'] === 'block') {
            $this->block($item, (string) $inventoryPlan['detail'], $product, $variant, false, $remote['status'], $inventoryPlan);

            return;
        }

        $alreadyLive = $remote['status'] === 'ACTIVE' && $missingPublications === [] && $inventoryPlan['action'] === 'skip';
        $item->forceFill([
            'current_status' => strtolower($remote['status']),
            'proposed_status' => 'active',
            'inventory_tracking' => $stack ? 'untracked' : ($variant?->inventory_tracked === true ? 'tracked' : 'untracked'),
            'current_inventory' => $inventoryPlan['current'],
            'proposed_inventory' => $inventoryPlan['proposed'],
            'inventory_write_required' => $inventoryPlan['action'] === 'write',
            'validation' => $alreadyLive || isset($inventoryPlan['warning'])
                ? ProductLaunchItem::VALIDATION_WARNING
                : ProductLaunchItem::VALIDATION_READY,
        ])->save();

        if ($inventoryPlan['action'] === 'write' && $item->inventory_written_at === null) {
            $this->assertEnabled($run);
            $this->inventory->setOnHand(
                $variant,
                (int) $inventoryPlan['proposed'],
                (int) $inventoryPlan['current'],
                'leighavenue-cms://product-launch/'.$run->id.'/'.$product->id,
                (string) $item->inventory_idempotency_key,
                null,
                new InventoryAuditContext(
                    correlationKey: (string) $item->inventory_idempotency_key,
                    workflow: 'product_launch',
                    action: 'launch_inventory',
                    actorType: 'user',
                    execution: 'queue',
                    userId: $run->user_id,
                    processName: 'Product launch',
                    explanation: 'Approved CMS inventory was applied while launching the product.',
                    productId: (int) $product->id,
                    variantId: (int) $variant->id,
                    sku: $variant->sku,
                ),
            );
            $item->forceFill(['inventory_written_at' => now()])->save();
            Variant::withoutEvents(function () use ($variant, $inventoryPlan): void {
                $variant->forceFill(['current_on_hand_quantity' => (int) $inventoryPlan['proposed']])->save();
            });
        }

        $confirmedStatus = $remote['status'];
        if ($confirmedStatus !== 'ACTIVE') {
            $this->assertEnabled($run);
            $confirmedStatus = $this->shopify->activate($product);
        }
        if ($missingPublications !== []) {
            $this->assertEnabled($run);
            $this->shopify->publish($product, $missingPublications);
        }
        $confirmedPublications = [];
        foreach ($selected as $publicationId) {
            if (! $this->shopify->publicationIsLive($product, $publicationId)) {
                throw new \RuntimeException('Shopify did not confirm publication to every selected sales channel.');
            }
            $confirmedPublications[] = $publicationId;
        }
        if ($confirmedStatus !== 'ACTIVE') {
            throw new \RuntimeException('Shopify did not confirm ACTIVE status.');
        }

        $previousStatus = (string) $product->status;
        if (strtolower($previousStatus) !== 'active') {
            Product::withoutEvents(function () use ($product): void {
                $product->forceFill(['status' => 'active'])->save();
            });
            ChangeLog::query()->create([
                'import_id' => $product->import_id,
                'product_id' => $product->id,
                'changed_by' => $run->user_id,
                'source' => 'product_launch',
                'model_type' => Product::class,
                'model_id' => $product->id,
                'field' => 'status',
                'old_value' => $previousStatus,
                'new_value' => 'active',
            ]);
        }

        $item->forceFill([
            'outcome' => $alreadyLive ? ProductLaunchItem::OUTCOME_SKIPPED : ProductLaunchItem::OUTCOME_LAUNCHED,
            'current_status' => 'active',
            'confirmed_publication_ids' => $confirmedPublications,
            'status_confirmed_at' => now(),
            'detail' => $alreadyLive
                ? 'Already active and published. Inventory was not written again.'
                : trim((string) ($inventoryPlan['warning'] ?? 'Shopify confirmed ACTIVE status and the selected sales channels.')),
        ])->save();
    }

    private function blocker(Product $product, ?Variant $variant, bool $stack): ?string
    {
        if (EngravedBeadConfiguration::fromProduct($product)->participating()) {
            try {
                app(EngravedBeadWriteGuard::class)->check();
                $pool = app(EngravedBeadStockPool::class);
                $member = $variant ? $pool->member($variant) : null;
                $source = $variant ? $pool->source($variant) : null;
                if ($member === null || $source === null || $member->manual_disabled || $member->automation_disabled) {
                    return 'Engraved family mapping and sellability must be approved before launch.';
                }
                $stock = $this->inventory->currentQuantities($source, $member->family->source_location_id);
                if ((int) $stock['available'] + (int) $member->family->automation_held_quantity <= $member->disable_threshold) {
                    return 'Shared physical bead stock is below the member launch threshold.';
                }
            } catch (\Throwable $error) {
                return $error->getMessage();
            }
        }
        if (! $product->isApprovedByTwo()) {
            return 'The product is not fully approved. Approval does not launch it, and launch requires approval.';
        }
        if ($product->has_errors) {
            return 'The product still has validation errors.';
        }
        if (trim((string) $product->shopify_id) === '') {
            return 'The Shopify product mapping is missing.';
        }
        if (! $variant instanceof Variant) {
            return 'Launch requires exactly one active variant so the SKU mapping is unambiguous.';
        }
        if (trim((string) $variant->sku) === '') {
            return 'The variant SKU is missing.';
        }
        if ($stack) {
            return null;
        }
        if ($variant->inventory_tracked === null) {
            return 'Inventory tracking is unknown, so launch will not guess a stock quantity.';
        }
        if ($variant->inventory_tracked === true && trim((string) $variant->shopify_inventory_item_id) === '') {
            return 'The Shopify inventory item mapping is missing.';
        }
        if ($this->pendingAdjustment($variant)) {
            return 'An unapproved inventory adjustment is still open, so launch will not write stock.';
        }

        return null;
    }

    /** @return array{action:string,current:?int,proposed:?int,detail?:string,warning?:string} */
    private function inventoryPlan(Variant $variant, ProductLaunchItem $item): array
    {
        if ($variant->inventory_tracked !== true) {
            return [
                'action' => 'skip',
                'current' => null,
                'proposed' => null,
                'warning' => 'Inventory is not tracked, so no quantity was sent.',
            ];
        }
        if ($variant->inventory_qty === null) {
            return ['action' => 'block', 'current' => null, 'proposed' => null, 'detail' => 'No approved CMS inventory quantity is available.'];
        }

        $live = $this->inventory->currentQuantities($variant);
        $proposed = (int) $variant->inventory_qty;
        $snapshot = $variant->current_on_hand_quantity;
        if ($item->inventory_written_at !== null || (int) $live['on_hand'] === $proposed) {
            return ['action' => 'skip', 'current' => (int) $live['on_hand'], 'proposed' => $proposed];
        }
        if ($snapshot === null || (int) $snapshot !== (int) $live['on_hand']) {
            return [
                'action' => 'block',
                'current' => (int) $live['on_hand'],
                'proposed' => $proposed,
                'detail' => 'CMS inventory does not match Shopify. Resolve the discrepancy before launch.',
            ];
        }
        if ((int) $live['committed'] > 0) {
            return [
                'action' => 'block',
                'current' => (int) $live['on_hand'],
                'proposed' => $proposed,
                'detail' => 'Shopify stock already includes committed sales, so launch will not overwrite it.',
            ];
        }

        // inventory_qty is AVAILABLE, never an approved absolute on-hand correction.
        return ['action' => 'block', 'current' => (int) $live['on_hand'], 'proposed' => $proposed,
            'detail' => 'Launch cannot use available stock as an on-hand target. Apply an approved inventory correction, refresh, then retry launch.'];
    }

    private function authorizeActor(?int $userId): void
    {
        $actor = $userId === null ? null : User::query()->find($userId);
        if (! $actor?->can('ShopifyPushProducts')) {
            throw new AuthorizationException('Shopify product permission is required to launch products.');
        }
    }

    private function assertEnabled(ProductLaunchRun $run): void
    {
        if (! config('product_launch.enabled', true)) {
            throw new \RuntimeException('Product launch is disabled; queued work cannot publish.');
        }
        $this->authorizeActor($run->user_id);
        if (array_diff((array) $run->publication_ids, (array) config('product_launch.publications', [])) !== []) {
            throw new \RuntimeException('Selected publication is no longer configured.');
        }
    }

    private function pendingAdjustment(Variant $variant): bool
    {
        return InventoryAdjustmentRequestItem::query()
            ->where('variant_id', $variant->id)
            ->whereHas('request', fn ($query) => $query->where('status', InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL))
            ->exists();
    }

    /** @param array<string, mixed>|null $inventoryPlan */
    private function block(
        ProductLaunchItem $item,
        string $detail,
        ?Product $product = null,
        ?Variant $variant = null,
        bool $stack = false,
        ?string $remoteStatus = null,
        ?array $inventoryPlan = null,
    ): void {
        $item->forceFill([
            'outcome' => ProductLaunchItem::OUTCOME_BLOCKED,
            'validation' => ProductLaunchItem::VALIDATION_BLOCKED,
            'current_status' => $remoteStatus !== null ? strtolower($remoteStatus) : $product?->status,
            'proposed_status' => 'active',
            'inventory_tracking' => $stack ? 'untracked' : ($variant?->inventory_tracked === true ? 'tracked' : ($variant?->inventory_tracked === false ? 'untracked' : 'unknown')),
            'current_inventory' => $inventoryPlan['current'] ?? $variant?->current_on_hand_quantity,
            'proposed_inventory' => $inventoryPlan['proposed'] ?? ($stack ? null : $variant?->inventory_qty),
            'detail' => $detail,
        ])->save();
    }

    private function failureDetail(ProductLaunchItem $item, string $message): string
    {
        $prefix = $item->inventory_written_at !== null
            ? 'Inventory was confirmed, but the launch did not finish. '
            : '';
        if ($item->status_confirmed_at !== null) {
            $prefix .= 'Status was confirmed. ';
        }

        return $prefix.'Shopify did not confirm the full launch. '.$message;
    }
}
