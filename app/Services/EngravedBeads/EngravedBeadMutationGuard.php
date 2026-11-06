<?php

namespace App\Services\EngravedBeads;

use App\Models\EngravedBeadFamilyMember;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Enforced at both HTTP gateways, including legacy queued generic writers. */
final class EngravedBeadMutationGuard
{
    public function graphql(string $query, array $variables): void
    {
        if (! preg_match('/\bmutation\b/', $query)) {
            return;
        }
        if (app(\App\Services\Procurement\SupplierReceiptMutationAuthorization::class)->permits($query,$variables)) return;
        $this->check($variables, $query);
    }

    public function rest(string $method, string $path, array $payload): void
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        foreach (['products' => 'Product', 'variants' => 'ProductVariant', 'inventory_items' => 'InventoryItem'] as $resource => $type) {
            if (preg_match('~(?:^|/)'.$resource.'/(\d+)~', $path, $match)) {
                $payload['_path_identity'] = 'gid://shopify/'.$type.'/'.$match[1];
            }
        }
        $this->check($payload, $path);
    }

    private function check(array $payload, string $operation): void
    {
        if (config('engraved_beads.enabled', true)
            && config('engraved_beads.inventory_writes_enabled', false)
            && config('engraved_beads.store_contract_verified', false)) {
            return;
        }
        $identities = [];
        $handles = [];
        $tagged = false;
        $walk = function (array $values) use (&$walk, &$identities, &$handles, &$tagged, $operation): void {
            foreach ($values as $key => $value) {
                if ($key === 'tags') {
                    $tags = is_array($value) ? implode(',', $value) : (string) $value;
                    $tagged = $tagged || EngravedBeadConfiguration::fromTags($tags)->participating()
                        || preg_match('/(?:^|[,\s])[a-z0-9-]+-bead-family(?:$|[,\s])/', $tags) === 1;
                }
                if (is_array($value)) {
                    $walk($value);
                } elseif (is_string($value) || is_int($value)) {
                    $value = (string) $value;
                    if (preg_match('~^gid://shopify/(Product|ProductVariant|InventoryItem)/\d+$~D', $value)) {
                        $identities[] = $value;
                    } elseif (ctype_digit($value)) {
                        $type = match ((string) $key) {
                            'product_id', 'productId' => 'Product',
                            'variant_id', 'variantId' => 'ProductVariant',
                            'inventory_item_id', 'inventoryItemId' => 'InventoryItem',
                            'id' => str_contains($operation, 'inventory') ? 'InventoryItem' : (str_contains($operation, 'variant') ? 'ProductVariant' : 'Product'),
                            default => null,
                        };
                        if ($type !== null) {
                            $identities[] = 'gid://shopify/'.$type.'/'.$value;
                        }
                    }
                    if ($key === 'handle') {
                        $handles[] = $value;
                    }
                }
            }
        };
        $walk($payload);
        // Include literal IDs used by older GraphQL writers, without caching mutable identity state.
        preg_match_all('~gid://shopify/(?:Product|ProductVariant|InventoryItem)/\d+~', $operation, $literal);
        $identities = array_unique(array_merge($identities, $literal[0]));
        if (! $tagged && ($identities !== [] || $handles !== []) && Schema::hasTable('products')) {
            $byType = ['Product' => [], 'ProductVariant' => [], 'InventoryItem' => []];
            foreach ($identities as $identity) {
                preg_match('~^gid://shopify/(Product|ProductVariant|InventoryItem)/(\d+)$~', $identity, $parts);
                $byType[$parts[1]][] = $identity;
                $byType[$parts[1]][] = $parts[2];
            }
            if (Schema::hasTable('engraved_bead_family_members') && EngravedBeadFamilyMember::query()
                ->where(fn ($q) => $q->whereIn('shopify_product_id', $byType['Product'])->orWhereIn('shopify_variant_id', $byType['ProductVariant'])
                    ->orWhereIn('shopify_inventory_item_id', $byType['InventoryItem']))->exists()) {
                $tagged = true; // Inactive and invalid discovered mappings still protect their identities.
            }
            $productIds = Variant::withoutGlobalScopes()->where(fn ($q) => $q->whereIn('shopify_id', $byType['ProductVariant'])
                ->orWhereIn('shopify_inventory_item_id', $byType['InventoryItem']))->pluck('product_id');
            foreach (Product::withoutGlobalScopes()->where(fn ($q) => $q->whereIn('shopify_id', $byType['Product'])
                ->orWhereIn('id', $productIds)->orWhereIn('handle', $handles))->get() as $product) {
                $tagged = $tagged || EngravedBeadConfiguration::fromProduct($product)->participating()
                    || $product->engraved_bead_configuration !== null || $product->engraved_bead_quantity !== null
                    || preg_match('/(?:^|[,\s])[a-z0-9-]+-bead-family(?:$|[,\s])/', (string) $product->tags) === 1;
            }
        }
        if ($tagged) {
            throw new RuntimeException('Engraved bead Shopify mutations are disabled until both inventory-write and store-contract flags are enabled.');
        }
    }
}
