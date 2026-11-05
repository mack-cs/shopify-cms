<?php

namespace App\Services\Procurement;

use App\Models\EngravedBeadFamilyMember;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadConfiguration;
use App\Services\EngravedBeads\EngravedBeadProcurementContract;
use Illuminate\Validation\ValidationException;

final class SupplierReceivingEligibility
{
    public function variantForLine(ProcurementSupplierOrderLine $line, bool $persist = false): Variant
    {
        $variant = $line->variant;
        if (! $variant) {
            $matches = Variant::active()->whereRaw('UPPER(TRIM(sku)) = ?', [strtoupper(trim($line->sku))])
                ->whereHas('product', fn ($q) => $q->procurementOrderEligible()
                    ->when($line->draft?->shopify_id, fn ($q, $id) => $q->where('shopify_id', $id)))->get();
            if ($matches->count() === 1) {
                $variant = $matches->first();
            }
        }
        if (! $variant) {
            throw ValidationException::withMessages(['sku' => "SKU {$line->sku}: create the product in Shopify, then synchronize its product, variant and inventory item mappings before receiving inventory."]);
        }
        $this->assertVariant($variant);
        if ($persist && (int) $line->variant_id !== (int) $variant->id) {
            $line->update(['variant_id' => $variant->id]);
            $line->setRelation('variant', $variant);
            app(SupplierOrderSummaryService::class)->refreshVariant($variant->fresh(['product', 'procurementIncomingStock']), null, 'cms:resolve-receiving-draft');
        }

        return $variant;
    }

    public function assertVariant(Variant $variant): void
    {
        $variant->loadMissing('product');
        $product = $variant->product;
        if (! $product || $product->isStackProduct()) {
            throw ValidationException::withMessages(['sku' => 'Stack products cannot receive independent supplier stock.']);
        }
        if (! in_array(strtolower((string) $product->status), ['active', 'draft', 'unlisted'], true)) {
            throw ValidationException::withMessages(['sku' => 'This product is not eligible for supplier receiving.']);
        }
        foreach (['product' => $product->shopify_id, 'variant' => $variant->shopify_id, 'inventory item' => $variant->shopify_inventory_item_id] as $name => $id) {
            if (blank($id)) {
                throw ValidationException::withMessages(['sku' => "SKU {$variant->sku}: synchronize its Shopify {$name} mapping before receiving inventory."]);
            }
        }
        if ($variant->inventory_tracked !== true) {
            throw ValidationException::withMessages(['sku' => "SKU {$variant->sku}: Shopify inventory tracking must be confirmed before receiving stock. Untracked multi-bead products are not stock sources."]);
        }
        if ($this->isBead($variant) && $this->approvedSource($variant) === null) {
            throw ValidationException::withMessages(['sku' => "SKU {$variant->sku}: only an approved, validated tracked 1-bead source may receive stock. Complete the existing family source approval; do not enable automation flags to bypass this check."]);
        }
    }

    public function isBead(Variant $variant): bool
    {
        return EngravedBeadConfiguration::fromProduct($variant->product)->participating()
            || preg_match('/(?:^|[,\s])[a-z0-9-]+-bead-family(?:$|[,\s])/', (string) $variant->product?->tags) === 1
            || EngravedBeadFamilyMember::where('shopify_inventory_item_id', $variant->shopify_inventory_item_id)->exists();
    }

    public function approvedSource(Variant $variant): ?array
    {
        return collect(app(EngravedBeadProcurementContract::class)->export()['families'])
            ->first(fn ($source) => $source['source_product_id'] === $variant->product?->shopify_id
                && $source['source_variant_id'] === $variant->shopify_id && $source['source_inventory_item_id'] === $variant->shopify_inventory_item_id);
    }
}
