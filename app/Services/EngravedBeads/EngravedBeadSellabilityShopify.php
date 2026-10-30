<?php

namespace App\Services\EngravedBeads;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\EngravedBeadFamilyMember;

class EngravedBeadSellabilityShopify
{
    public function __construct(private readonly ShopifyGraphqlGateway $gateway) {}

    public function read(EngravedBeadFamilyMember $member, string $location): array
    {
        $data = $this->gateway->graphql(<<<'GQL'
query BeadSellabilityState($id: ID!) {
  productVariant(id: $id) {
    id sku availableForSale inventoryPolicy product { id status }
    inventoryItem { id tracked inventoryLevels(first: 50) {
      pageInfo { hasNextPage }
      nodes { location { id isActive fulfillsOnlineOrders }
        quantities(names: ["available", "on_hand", "committed", "reserved", "safety_stock"]) { name quantity } }
    } }
  }
}
GQL, ['id' => $member->shopify_variant_id]);
        $v = $data['productVariant'] ?? null;
        $levels = data_get($v, 'inventoryItem.inventoryLevels.nodes');
        if (! is_array($v) || $v['id'] !== $member->shopify_variant_id || $v['sku'] !== $member->sku
            || data_get($v, 'product.id') !== $member->shopify_product_id
            || data_get($v, 'inventoryItem.id') !== $member->shopify_inventory_item_id
            || ! is_bool($v['availableForSale'] ?? null) || ! is_bool(data_get($v, 'inventoryItem.tracked'))
            || ! is_array($levels) || count($levels) !== 1 || data_get($levels, '0.location.id') !== $location
            || data_get($v, 'inventoryItem.inventoryLevels.pageInfo.hasNextPage') !== false
            || data_get($levels, '0.location.isActive') !== true || data_get($levels, '0.location.fulfillsOnlineOrders') !== true) {
            throw new \RuntimeException('Shopify member identity or single-location inventory contract could not be verified.');
        }
        $quantities = collect($levels[0]['quantities'] ?? [])->pluck('quantity', 'name')->all();
        foreach (['available', 'on_hand', 'committed', 'reserved', 'safety_stock'] as $name) {
            if (! isset($quantities[$name]) || ! is_int($quantities[$name])) {
                throw new \RuntimeException('Shopify returned incomplete inventory quantities.');
            }
        }

        return ['tracked' => $v['inventoryItem']['tracked'], 'sellable' => $v['availableForSale'],
            'policy' => $v['inventoryPolicy'], 'status' => $v['product']['status']] + $quantities;
    }

    public function tracking(EngravedBeadFamilyMember $member, bool $tracked): void
    {
        app(EngravedBeadWriteGuard::class)->check();
        if ($member->required_beads === 1 && ! $tracked) {
            throw new \LogicException('The source must always remain tracked.');
        }
        $data = $this->gateway->graphql(<<<'GQL'
mutation BeadTracking($id: ID!, $input: InventoryItemInput!) {
  inventoryItemUpdate(id: $id, input: $input) { inventoryItem { id tracked } userErrors { message } }
}
GQL, ['id' => $member->shopify_inventory_item_id, 'input' => ['tracked' => $tracked]]);
        $this->confirmed($data, 'inventoryItemUpdate');
        if (data_get($data, 'inventoryItemUpdate.inventoryItem.tracked') !== $tracked) {
            throw new \RuntimeException('Shopify did not confirm tracking state.');
        }
    }

    public function deny(EngravedBeadFamilyMember $member): void
    {
        app(EngravedBeadWriteGuard::class)->check();
        $data = $this->gateway->graphql(<<<'GQL'
mutation BeadDenyOverselling($product: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $product, variants: $variants) { productVariants { id inventoryPolicy } userErrors { message } }
}
GQL, ['product' => $member->shopify_product_id, 'variants' => [['id' => $member->shopify_variant_id, 'inventoryPolicy' => 'DENY']]]);
        $this->confirmed($data, 'productVariantsBulkUpdate');
        if (data_get($data, 'productVariantsBulkUpdate.productVariants.0.inventoryPolicy') !== 'DENY') {
            throw new \RuntimeException('Shopify did not confirm deny inventory policy.');
        }
    }

    public function zero(EngravedBeadFamilyMember $member, string $location, int $expected, string $key): void
    {
        app(EngravedBeadWriteGuard::class)->check();
        if ($member->required_beads === 1) {
            throw new \LogicException('Never zero the physical source.');
        }
        $data = $this->gateway->graphql(<<<'GQL'
mutation BeadSoldOut($input: InventorySetQuantitiesInput!, $key: String!) {
  inventorySetQuantities(input: $input) @idempotent(key: $key) { inventoryAdjustmentGroup { createdAt } userErrors { message } }
}
GQL, ['key' => $key, 'input' => ['name' => 'available', 'reason' => 'correction',
            'referenceDocumentUri' => 'leighavenue-cms://bead-sellability/'.$key,
            'quantities' => [['inventoryItemId' => $member->shopify_inventory_item_id, 'locationId' => $location,
                'quantity' => 0, 'changeFromQuantity' => $expected]]]]);
        $this->confirmed($data, 'inventorySetQuantities');
        if (! is_array(data_get($data, 'inventorySetQuantities.inventoryAdjustmentGroup'))) {
            throw new \RuntimeException('Shopify did not confirm the zero-quantity operation.');
        }
    }

    private function confirmed(array $data, string $name): void
    {
        if (! is_array($data[$name] ?? null) || data_get($data, $name.'.userErrors') !== []) {
            throw new \RuntimeException('Shopify failed '.$name.': '.json_encode(data_get($data, $name.'.userErrors')));
        }
    }
}
