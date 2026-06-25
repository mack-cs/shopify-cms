<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use League\Csv\Writer;

final class ProductInventoryCsvExporter
{
    /**
     * @return array<int,string>
     */
    public function headers(): array
    {
        return [
            'product_id',
            'shopify_product_id',
            'handle',
            'product_title',
            'product_status',
            'variant_id',
            'shopify_variant_id',
            'sku',
            'inventory_tracked',
            'stock',
            'pending_push',
            'from_shopify_at',
            'pushed_to_shopify_at',
        ];
    }

    public function exportToString(): string
    {
        $writer = Writer::createFromString();
        $writer->insertOne($this->headers());

        $variants = Variant::query()
            ->active()
            ->with('product')
            ->whereHas('product', fn (Builder $query): Builder => $query
                ->whereRaw('LOWER(COALESCE(status, "")) != ?', ['archived']))
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();

        foreach ($variants as $variant) {
            if (!$variant instanceof Variant || !$variant->product instanceof Product) {
                continue;
            }

            $writer->insertOne($this->row($variant, $variant->product));
        }

        return $writer->toString();
    }

    /**
     * @return array<int,string>
     */
    private function row(Variant $variant, Product $product): array
    {
        return [
            (string) $product->id,
            trim((string) ($product->shopify_id ?? '')),
            trim((string) ($product->handle ?? '')),
            trim((string) ($product->title ?? '')),
            trim((string) ($product->status ?? '')),
            (string) $variant->id,
            trim((string) ($variant->shopify_id ?? '')),
            trim((string) ($variant->sku ?? '')),
            $this->boolValue($variant->inventory_tracked),
            $variant->inventory_qty === null ? '' : (string) ((int) $variant->inventory_qty),
            $this->boolValue($variant->inventory_local_dirty),
            $variant->inventory_last_synced_at?->toDateTimeString() ?? '',
            $variant->inventory_pushed_at?->toDateTimeString() ?? '',
        ];
    }

    private function boolValue(?bool $value): string
    {
        return match ($value) {
            true => 'true',
            false => 'false',
            null => '',
        };
    }
}
