<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Carbon;

final class StackInventoryAuditService
{
    /** @var array<int, Product|null> */
    private array $products = [];

    /**
     * @return array{
     *     product_id:int,
     *     title:string,
     *     sku:?string,
     *     quantity_per_stack:int,
     *     available:?int,
     *     on_hand:?int,
     *     tracked:?bool,
     *     status:string,
     *     reason:?string,
     *     synced_at:?Carbon
     * }|null
     */
    public function component(NewProductDraft $stack, int $position): ?array
    {
        $componentIds = $this->componentIds($stack);
        $productId = $componentIds[$position - 1] ?? null;

        if ($productId === null) {
            return null;
        }

        $quantityPerStack = $this->quantityPerStack($stack, $productId);
        $product = $this->product($productId);

        if (! $product instanceof Product) {
            return [
                'product_id' => $productId,
                'title' => "Missing product #{$productId}",
                'sku' => null,
                'quantity_per_stack' => $quantityPerStack,
                'available' => null,
                'on_hand' => null,
                'tracked' => null,
                'status' => 'Missing',
                'reason' => 'The configured component product is missing locally.',
                'synced_at' => null,
            ];
        }

        $variant = $product->variants->sortBy('id')->first();
        if (! $variant instanceof Variant) {
            return [
                'product_id' => $productId,
                'title' => $this->productTitle($product),
                'sku' => null,
                'quantity_per_stack' => $quantityPerStack,
                'available' => null,
                'on_hand' => null,
                'tracked' => null,
                'status' => 'No variant',
                'reason' => 'The component has no active local variant.',
                'synced_at' => null,
            ];
        }

        $available = $variant->current_available_quantity ?? $variant->inventory_qty;
        $status = strtolower(trim((string) $product->status));
        $reason = null;

        if ($status !== 'active') {
            $health = 'Inactive';
            $reason = $status === ''
                ? 'The component product has no local status.'
                : 'The component product is '.strtoupper($status).'.';
        } elseif ($variant->inventory_tracked === false) {
            $health = 'Not tracked';
        } elseif ($available === null) {
            $health = 'Unknown';
            $reason = 'Available inventory has not been synced yet.';
        } elseif ((int) $available < $quantityPerStack) {
            $health = (int) $available <= 0 ? 'Out of stock' : 'Insufficient';
            $reason = "Needs {$quantityPerStack} per stack; {$available} available.";
        } else {
            $health = 'In stock';
        }

        return [
            'product_id' => $productId,
            'title' => $this->productTitle($product),
            'sku' => trim((string) $variant->sku) ?: null,
            'quantity_per_stack' => $quantityPerStack,
            'available' => $available !== null ? (int) $available : null,
            'on_hand' => $variant->current_on_hand_quantity,
            'tracked' => $variant->inventory_tracked,
            'status' => $health,
            'reason' => $reason,
            'synced_at' => $variant->inventory_last_synced_at,
        ];
    }

    /** @return array{status:string,reason:?string} */
    public function health(NewProductDraft $stack): array
    {
        $componentIds = $this->componentIds($stack);
        if ($componentIds === []) {
            return ['status' => 'No components', 'reason' => 'No component products are configured.'];
        }

        foreach (array_keys($componentIds) as $index) {
            $component = $this->component($stack, $index + 1);
            if ($component === null || in_array($component['status'], ['In stock', 'Not tracked'], true)) {
                continue;
            }

            return [
                'status' => 'Out of stock',
                'reason' => 'Component '.($index + 1).": {$component['title']} - ".($component['reason'] ?? $component['status']),
            ];
        }

        return ['status' => 'Ready', 'reason' => null];
    }

    /** @return array<int, int> */
    private function componentIds(NewProductDraft $stack): array
    {
        return collect((array) $stack->bundle_product_ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function quantityPerStack(NewProductDraft $stack, int $productId): int
    {
        foreach ((array) $stack->bundle_component_quantities as $row) {
            if (is_array($row) && (int) ($row['product_id'] ?? 0) === $productId) {
                return max(1, (int) ($row['quantity'] ?? 1));
            }
        }

        return 1;
    }

    private function product(int $productId): ?Product
    {
        if (! array_key_exists($productId, $this->products)) {
            $this->products[$productId] = Product::query()
                ->with(['variants' => fn ($query) => $query->orderBy('id')])
                ->find($productId);
        }

        return $this->products[$productId];
    }

    private function productTitle(Product $product): string
    {
        return trim((string) $product->title) ?: "Product #{$product->id}";
    }
}
