<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\ManualStockTransaction;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ManualStockTransactionService
{
    public function __construct(private readonly ShopifyInventoryAdjustmentService $inventory) {}

    public function prepare(ManualStockTransaction $transaction): ManualStockTransaction
    {
        if ($transaction->impacts()->where('status', 'completed')->exists()) {
            throw new RuntimeException('This transaction has already changed inventory and cannot be rebuilt.');
        }

        $transaction->load('items.product.variants');
        if ($transaction->items->isEmpty()) {
            throw new RuntimeException('Add at least one product before preparing the transaction.');
        }

        $aggregated = [];
        foreach ($transaction->items as $item) {
            $product = $item->product;
            if (! $product || $item->quantity < 1) {
                throw new RuntimeException('Every line requires a valid product and positive quantity.');
            }
            $item->update(['product_title' => $product->title, 'sku' => $product->variants->first()?->sku]);
            $draft = $this->stackDraft($product);
            $parts = $draft ? $this->components($draft) : [$this->singleVariant($product)->id => 1];
            $item->update(['is_stack' => (bool) $draft, 'processing_result' => $draft ? 'Expanded into stack components.' : 'Direct product inventory.']);

            foreach ($parts as $variantId => $perUnit) {
                $variant = Variant::query()->with('product')->findOrFail($variantId);
                $required = $item->quantity * $perUnit;
                if (! isset($aggregated[$variantId])) {
                    $aggregated[$variantId] = ['variant' => $variant, 'quantity' => 0, 'sources' => []];
                }
                $aggregated[$variantId]['quantity'] += $required;
                $aggregated[$variantId]['sources'][] = [
                    'item_id' => $item->id, 'product' => $product->title, 'ordered_quantity' => $item->quantity,
                    'component_quantity_each' => $perUnit, 'component_quantity_total' => $required,
                ];
            }
        }

        $transaction->impacts()->delete();
        $insufficient = [];
        foreach ($aggregated as $entry) {
            /** @var Variant $variant */
            $variant = $entry['variant'];
            $live = $transaction->is_historical ? null : $this->inventory->currentQuantities($variant);
            if ($live && $live['available'] < $entry['quantity']) {
                $insufficient[] = ($variant->sku ?: $variant->product?->title)." needs {$entry['quantity']}; {$live['available']} available";
            }
            $transaction->impacts()->create([
                'variant_id' => $variant->id, 'sku' => (string) $variant->sku,
                'product_title' => (string) ($variant->product?->title ?: $variant->sku),
                'quantity_required' => $entry['quantity'], 'sources' => $entry['sources'],
                'available_before' => $live['available'] ?? null, 'on_hand_before' => $live['on_hand'] ?? null,
                'shopify_location_id' => $live['location_id'] ?? null,
                'idempotency_key' => (string) Str::uuid(), 'status' => $transaction->is_historical ? 'historical_pending' : 'pending',
            ]);
        }

        $transaction->update([
            'status' => $insufficient ? 'needs_attention' : 'ready',
            'inventory_update_status' => $transaction->is_historical ? 'historical_pending' : ($insufficient ? 'blocked' : 'previewed'),
            'last_error' => $insufficient ? 'Insufficient Shopify Available inventory: '.implode('; ', $insufficient) : null,
        ]);

        return $transaction->fresh(['items', 'impacts']);
    }

    public function process(ManualStockTransaction $transaction, ?int $userId = null): ManualStockTransaction
    {
        if ($transaction->impacts()->doesntExist()) $transaction = $this->prepare($transaction);
        $transaction = DB::transaction(function () use ($transaction, $userId): ManualStockTransaction {
            $locked = ManualStockTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status === 'completed') return $locked;
            if ($locked->status === 'processing' && $locked->updated_at?->gt(now()->subMinutes(10))) return $locked;
            $locked->update(['status' => 'processing', 'inventory_update_status' => 'processing', 'processed_by' => $userId, 'last_error' => null]);
            return $locked;
        });
        if ($transaction->status === 'completed') return $transaction->fresh(['impacts']);
        if ($transaction->wasChanged() === false && $transaction->status === 'processing' && $transaction->updated_at?->gt(now()->subMinutes(10))) {
            return $transaction->fresh(['impacts']);
        }

        if ($transaction->is_historical || $transaction->processing_mode === ManualStockTransaction::MODE_HISTORICAL) {
            $transaction->impacts()->whereNotIn('status', ['completed'])->update(['status' => 'historical', 'processed_at' => now()]);
            $transaction->update(['status' => 'completed', 'inventory_update_status' => 'historical_no_adjustment', 'processed_by' => $userId, 'processed_at' => now(), 'last_error' => null]);
            $this->audit($transaction, $userId, 'historical_recorded');
            return $transaction->fresh(['impacts']);
        }

        $pending = $transaction->impacts()->with('variant.product')->where('status', '!=', 'completed')->get();
        $short = [];
        foreach ($pending as $impact) {
            $live = $this->inventory->currentQuantities($impact->variant, $impact->shopify_location_id);
            $impact->update(array_filter([
                'available_before' => $impact->attempts === 0 ? $live['available'] : null,
                'on_hand_before' => $impact->attempts === 0 ? $live['on_hand'] : null,
                'shopify_location_id' => $live['location_id'],
            ], fn ($value) => $value !== null));
            if ($live['available'] < $impact->quantity_required) $short[] = "{$impact->sku}: needs {$impact->quantity_required}, {$live['available']} available";
        }
        if ($short) {
            $transaction->update(['status' => 'needs_attention', 'inventory_update_status' => 'blocked', 'last_error' => 'Insufficient Shopify Available inventory: '.implode('; ', $short)]);
            throw new RuntimeException($transaction->last_error);
        }

        foreach ($pending as $impact) {
            try {
                $impact->increment('attempts');
                $impact->update(['status' => 'processing', 'error_message' => null]);
                $response = $this->inventory->decreaseOnHand($impact->variant, $impact->quantity_required,
                    "gid://la-cms/ManualStockTransaction/{$transaction->id}/Impact/{$impact->id}", $impact->idempotency_key,
                    $impact->shopify_location_id, (int) $impact->on_hand_before);
                $after = $this->inventory->currentQuantities($impact->variant, $impact->shopify_location_id);
                $impact->variant->update(['current_available_quantity' => $after['available'], 'current_on_hand_quantity' => $after['on_hand'],
                    'current_committed_quantity' => $after['committed'], 'inventory_last_synced_at' => now(), 'inventory_sync_error' => null]);
                $impact->update(['status' => 'completed', 'shopify_response' => $response, 'available_after' => $after['available'],
                    'on_hand_after' => $after['on_hand'], 'processed_at' => now()]);
                $this->auditImpact($transaction, $impact, $userId);
            } catch (Throwable $e) {
                $impact->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }
        }

        $failed = $transaction->impacts()->where('status', 'failed')->count();
        $done = $transaction->impacts()->where('status', 'completed')->count();
        $transaction->update([
            'status' => $failed ? ($done ? 'partially_failed' : 'failed') : 'completed',
            'inventory_update_status' => $failed ? ($done ? 'partial_failure' : 'failed') : 'completed',
            'processed_at' => $failed ? null : now(),
            'last_error' => $failed ? "{$failed} inventory adjustment(s) failed. Retry processes failed rows only." : null,
        ]);
        return $transaction->fresh(['impacts']);
    }

    private function stackDraft(Product $product): ?NewProductDraft
    {
        return NewProductDraft::query()->whereNotNull('bundle_product_ids')->where(function ($q) use ($product): void {
            if ($product->shopify_id) $q->where('shopify_id', $product->shopify_id); else $q->whereRaw('1 = 0');
            if ($product->handle) $q->orWhere('handle', $product->handle);
            $skus = $product->variants()->whereNotNull('sku')->pluck('sku');
            if ($skus->isNotEmpty()) $q->orWhereIn('sku', $skus);
        })->first();
    }

    /** @return array<int,int> variant id => quantity */
    private function components(NewProductDraft $draft): array
    {
        $qty = collect((array) $draft->bundle_component_quantities)->mapWithKeys(fn ($r) => is_array($r) && (int)($r['product_id'] ?? 0) > 0 ? [(int)$r['product_id'] => max(1, (int)($r['quantity'] ?? 1))] : []);
        $result = [];
        foreach ((array) $draft->bundle_product_ids as $productId) {
            $product = Product::query()->find((int) $productId);
            if (! $product) throw new RuntimeException("Stack component product {$productId} was not found.");
            $result[$this->singleVariant($product)->id] = (int) ($qty[(int)$productId] ?? 1);
        }
        if (! $result) throw new RuntimeException("Stack {$draft->title} has no configured components.");
        return $result;
    }

    private function singleVariant(Product $product): Variant
    {
        $variants = $product->variants()->whereNotNull('shopify_inventory_item_id')->get();
        if ($variants->count() !== 1) throw new RuntimeException("{$product->title} must have exactly one active inventory-tracked variant.");
        return $variants->first();
    }

    private function auditImpact(ManualStockTransaction $transaction, $impact, ?int $userId): void
    {
        ChangeLog::create(['product_id' => $impact->variant->product_id, 'changed_by' => $userId, 'source' => 'manual_stock_transaction',
            'model_type' => ManualStockTransaction::class, 'model_id' => $transaction->id, 'field' => 'inventory_on_hand',
            'old_value' => (string) $impact->on_hand_before, 'new_value' => (string) $impact->on_hand_after]);
    }

    private function audit(ManualStockTransaction $transaction, ?int $userId, string $field): void
    {
        ChangeLog::create(['changed_by' => $userId, 'source' => 'manual_stock_transaction', 'model_type' => ManualStockTransaction::class,
            'model_id' => $transaction->id, 'field' => $field, 'old_value' => null, 'new_value' => $transaction->reference_number]);
    }
}
