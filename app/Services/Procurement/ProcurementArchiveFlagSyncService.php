<?php

namespace App\Services\Procurement;

use App\Jobs\SyncMarkForArchiveMetafieldJob;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ProductShopifyUpdater;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcurementArchiveFlagSyncService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNMAPPED = 'unmapped';

    public const METAFIELD_NAMESPACE = 'custom';

    public const METAFIELD_KEY = 'mark_for_archive';

    public function __construct(private readonly ProductShopifyUpdater $updater) {}

    /**
     * Recompute desired product archive flag from variant Ignore rows and queue Shopify sync when needed.
     *
     * @return array{product_id:int,desired:?bool,queued:bool,status:?string,reason:?string}
     */
    public function refreshProduct(Product $product, bool $queue = true): array
    {
        $desired = $this->desiredFlagForProduct($product);
        $needsQueue = false;

        DB::transaction(function () use ($product, $desired, &$needsQueue): void {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $previousComparable = $this->nullableBool($locked->mark_for_archive);
            $updates = ['mark_for_archive' => $desired];

            if ($previousComparable !== $desired) {
                $updates['mark_for_archive_desired_version'] = (int) $locked->mark_for_archive_desired_version + 1;
                $updates['mark_for_archive_source_changed_at'] = now();
            }

            if ($desired === null) {
                // No procurement Ignore rows — preserve any existing CMS/Shopify archive state.
                $product->refresh();

                return;
            }

            $shopifyId = trim((string) ($locked->shopify_id ?? ''));
            if ($shopifyId === '') {
                $updates['mark_for_archive_sync_status'] = self::STATUS_UNMAPPED;
                $updates['mark_for_archive_sync_error'] = 'Product has no Shopify product ID.';
                $locked->forceFill($updates)->save();
                $product->refresh();

                return;
            }

            $alreadySynced = $locked->mark_for_archive_sync_status === self::STATUS_SYNCED
                && $this->nullableBool($locked->mark_for_archive_synced_value) === $desired
                && $previousComparable === $desired;
            if ($alreadySynced) {
                $locked->forceFill($updates)->save();
                $product->refresh();

                return;
            }

            $updates['mark_for_archive_sync_status'] = self::STATUS_PENDING;
            $updates['mark_for_archive_sync_error'] = null;
            $locked->forceFill($updates)->save();
            $needsQueue = true;
            $product->refresh();
        });

        $product->refresh();
        $shouldQueue = $queue
            && $needsQueue
            && $this->nullableBool($product->mark_for_archive) !== null
            && $product->mark_for_archive_sync_status === self::STATUS_PENDING
            && trim((string) ($product->shopify_id ?? '')) !== '';

        if ($shouldQueue) {
            $this->dispatchSync($product);
        }

        return [
            'product_id' => (int) $product->id,
            'desired' => $this->nullableBool($product->mark_for_archive),
            'queued' => $shouldQueue,
            'status' => $product->mark_for_archive_sync_status,
            'reason' => $product->mark_for_archive_sync_error,
        ];
    }

    /**
     * @param  iterable<int, Product|int>  $products
     * @return array{refreshed:int,queued:int}
     */
    public function refreshProducts(iterable $products, bool $queue = true): array
    {
        $refreshed = 0;
        $queued = 0;
        foreach ($products as $product) {
            $model = $product instanceof Product
                ? $product
                : Product::query()->find((int) $product);
            if (! $model instanceof Product) {
                continue;
            }
            $result = $this->refreshProduct($model, $queue);
            $refreshed++;
            if ($result['queued']) {
                $queued++;
            }
        }

        return ['refreshed' => $refreshed, 'queued' => $queued];
    }

    public function desiredFlagForProduct(Product $product): ?bool
    {
        $product->loadMissing(['allVariants.procurementIncomingStock']);
        $flags = [];
        foreach ($product->allVariants as $variant) {
            if (! $variant instanceof Variant) {
                continue;
            }
            if ($variant->sync_state === Variant::SYNC_STATE_LOCAL_DELETED
                || $variant->sync_state === Variant::SYNC_STATE_REMOTE_DELETED) {
                continue;
            }
            $stock = $variant->procurementIncomingStock;
            if ($stock === null) {
                continue;
            }
            $flags[] = (bool) $stock->ignore;
        }

        if ($flags === []) {
            return null;
        }

        // Product metafield is true when any procurement SKU on the product is ignored.
        return in_array(true, $flags, true);
    }

    /**
     * Apply the desired CMS value to Shopify. Idempotent and version-guarded.
     *
     * @return array{status:string,skipped:bool,value:?bool,error:?string}
     */
    public function syncProduct(Product $product, ?int $expectedVersion = null, bool $force = false): array
    {
        $product->refresh();
        $desired = $this->nullableBool($product->mark_for_archive);
        if ($desired === null) {
            $computed = $this->desiredFlagForProduct($product);
            if ($computed === null) {
                return [
                    'status' => self::STATUS_UNMAPPED,
                    'skipped' => true,
                    'value' => null,
                    'error' => 'No procurement Ignore value is available for this product.',
                ];
            }
            $this->refreshProduct($product, queue: false);
            $product->refresh();
            $desired = $this->nullableBool($product->mark_for_archive);
            if ($desired === null) {
                return [
                    'status' => self::STATUS_UNMAPPED,
                    'skipped' => true,
                    'value' => null,
                    'error' => 'No procurement Ignore value is available for this product.',
                ];
            }
        }
        $version = (int) $product->mark_for_archive_desired_version;
        if ($expectedVersion !== null && $expectedVersion < $version) {
            return [
                'status' => (string) $product->mark_for_archive_sync_status,
                'skipped' => true,
                'value' => $desired,
                'error' => 'Stale sync job superseded by a newer procurement Ignore value.',
            ];
        }

        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        if ($shopifyId === '') {
            $product->forceFill([
                'mark_for_archive_sync_status' => self::STATUS_UNMAPPED,
                'mark_for_archive_sync_error' => 'Product has no Shopify product ID.',
            ])->save();

            return [
                'status' => self::STATUS_UNMAPPED,
                'skipped' => true,
                'value' => $desired,
                'error' => 'Product has no Shopify product ID.',
            ];
        }

        if (! $force
            && $product->mark_for_archive_sync_status === self::STATUS_SYNCED
            && $this->nullableBool($product->mark_for_archive_synced_value) === $desired) {
            return [
                'status' => self::STATUS_SYNCED,
                'skipped' => true,
                'value' => $desired,
                'error' => null,
            ];
        }

        try {
            $confirmed = $this->updater->setMarkForArchiveMetafield($product, $desired);
            $product->refresh();
            if ($expectedVersion !== null && (int) $product->mark_for_archive_desired_version > $expectedVersion) {
                return [
                    'status' => self::STATUS_PENDING,
                    'skipped' => true,
                    'value' => $desired,
                    'error' => 'Shopify write succeeded but a newer procurement value arrived; leaving sync pending.',
                ];
            }

            $product->forceFill([
                'mark_for_archive_sync_status' => self::STATUS_SYNCED,
                'mark_for_archive_synced_value' => (bool) $confirmed['value'],
                'mark_for_archive_synced_at' => now(),
                'mark_for_archive_sync_error' => null,
            ])->save();

            return [
                'status' => self::STATUS_SYNCED,
                'skipped' => false,
                'value' => (bool) $confirmed['value'],
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            $message = trim($exception->getMessage());
            $product->forceFill([
                'mark_for_archive_sync_status' => self::STATUS_FAILED,
                'mark_for_archive_sync_error' => mb_substr($message !== '' ? $message : class_basename($exception), 0, 4000),
            ])->save();

            Log::error('Mark-for-archive metafield sync failed', [
                'product_id' => $product->id,
                'shopify_id' => $shopifyId,
                'desired' => $desired,
                'version' => $version,
                'error' => $message,
            ]);

            return [
                'status' => self::STATUS_FAILED,
                'skipped' => false,
                'value' => $desired,
                'error' => $message,
            ];
        }
    }

    /**
     * @return array{
     *   examined:int,
     *   would_update:int,
     *   updated:int,
     *   skipped:int,
     *   unmapped:int,
     *   failed:int,
     *   conflicts:array<int,array{product_id:int,sku:string,message:string}>
     * }
     */
    public function reconcile(bool $dryRun = true, ?int $limit = null, ?int $productId = null, bool $force = false): array
    {
        $summary = [
            'examined' => 0,
            'would_update' => 0,
            'updated' => 0,
            'skipped' => 0,
            'unmapped' => 0,
            'failed' => 0,
            'conflicts' => [],
        ];

        $query = Product::query()
            ->whereHas('allVariants.procurementIncomingStock')
            ->with(['allVariants.procurementIncomingStock'])
            ->orderBy('id');
        if ($productId !== null) {
            $query->whereKey($productId);
        }
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $product) {
            $summary['examined']++;
            $conflict = $this->conflictingIgnoreValues($product);
            if ($conflict !== null) {
                $summary['conflicts'][] = $conflict;
            }

            $refresh = $this->refreshProduct($product, queue: false);
            $desired = $refresh['desired'];
            if ($desired === null) {
                $summary['skipped']++;
                continue;
            }

            $product->refresh();
            if (trim((string) ($product->shopify_id ?? '')) === '') {
                $summary['unmapped']++;
                continue;
            }

            $alreadySynced = $product->mark_for_archive_sync_status === self::STATUS_SYNCED
                && $this->nullableBool($product->mark_for_archive_synced_value) === $desired;
            if ($alreadySynced && ! $force) {
                $summary['skipped']++;
                continue;
            }

            $summary['would_update']++;
            if ($dryRun) {
                continue;
            }

            $result = $this->syncProduct($product, (int) $product->mark_for_archive_desired_version, $force);
            if ($result['status'] === self::STATUS_SYNCED && ! $result['skipped']) {
                $summary['updated']++;
            } elseif ($result['status'] === self::STATUS_UNMAPPED) {
                $summary['unmapped']++;
            } elseif ($result['status'] === self::STATUS_FAILED) {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /** @return array{product_id:int,sku:string,message:string}|null */
    public function conflictingIgnoreValues(Product $product): ?array
    {
        $product->loadMissing(['allVariants.procurementIncomingStock']);
        $seen = [];
        $sku = '';
        foreach ($product->allVariants as $variant) {
            $stock = $variant->procurementIncomingStock;
            if ($stock === null) {
                continue;
            }
            $seen[(bool) $stock->ignore ? '1' : '0'] = true;
            $sku = strtoupper(trim((string) $variant->sku));
        }
        if (count($seen) < 2) {
            return null;
        }

        return [
            'product_id' => (int) $product->id,
            'sku' => $sku,
            'message' => 'Product variants have conflicting Ignore values; mark_for_archive uses OR (any TRUE wins).',
        ];
    }

    public function dispatchSync(Product $product): void
    {
        dispatch(
            (new SyncMarkForArchiveMetafieldJob(
                (int) $product->id,
                (int) $product->mark_for_archive_desired_version,
            ))
                ->onConnection((string) config('procurement.queue_connection', 'database'))
                ->onQueue((string) config('procurement.queue', 'procurement'))
        );
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (bool) $value;
    }

    /** Retry failed/pending products without requiring another sheet upload. */
    public function retryPending(bool $queue = true, ?int $limit = null): array
    {
        $query = Product::query()
            ->whereIn('mark_for_archive_sync_status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->whereNotNull('mark_for_archive')
            ->orderBy('id');
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $queued = 0;
        $synced = 0;
        $failed = 0;
        foreach ($query->cursor() as $product) {
            if ($queue) {
                $product->forceFill([
                    'mark_for_archive_sync_status' => self::STATUS_PENDING,
                    'mark_for_archive_sync_error' => null,
                ])->save();
                $this->dispatchSync($product);
                $queued++;
                continue;
            }

            $result = $this->syncProduct($product, (int) $product->mark_for_archive_desired_version);
            if ($result['status'] === self::STATUS_SYNCED) {
                $synced++;
            } elseif ($result['status'] === self::STATUS_FAILED) {
                $failed++;
            }
        }

        return ['queued' => $queued, 'synced' => $synced, 'failed' => $failed];
    }
}
