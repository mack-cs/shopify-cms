<?php

namespace App\Services\Shopify;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyInventorySnapshot;
use App\Models\Variant;

class ShopifyInventoryAdjustmentService
{
    public function __construct(private readonly ShopifyGraphqlGateway $client) {}

    public function resolveLocationId(Variant $variant): string
    {
        $locationId = trim((string) config('services.shopify.inventory_location_id'));
        if ($locationId !== '') {
            return $locationId;
        }

        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId !== '') {
            $locationId = trim((string) ShopifyInventorySnapshot::query()
                ->where('shopify_inventory_item_id', $inventoryItemId)
                ->whereNotNull('shopify_location_id')
                ->latest('id')
                ->value('shopify_location_id'));
        }
        if ($locationId === '') {
            $locations = $this->client->graphql('query ProcurementLocation { locations(first: 1) { nodes { id } } }');
            $locationId = trim((string) data_get($locations, 'locations.nodes.0.id'));
        }
        if ($locationId === '') {
            throw new \RuntimeException('No Shopify inventory location could be resolved.');
        }

        return $locationId;
    }

    public function increaseAvailable(Variant $variant, int $quantity, string $referenceUri, ?string $locationId = null): void
    {
        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId === '') {
            throw new \RuntimeException("Variant {$variant->id} has no Shopify inventory item ID.");
        }
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));

        $data = $this->client->graphql(<<<'GRAPHQL'
mutation ReceiveProcurementStock($input: InventoryAdjustQuantitiesInput!) {
  inventoryAdjustQuantities(input: $input) {
    inventoryAdjustmentGroup { createdAt reason referenceDocumentUri }
    userErrors { field message }
  }
}
GRAPHQL, ['input' => [
            'name' => 'available', 'reason' => 'received', 'referenceDocumentUri' => $referenceUri,
            'changes' => [['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'delta' => $quantity]],
        ]]);
        $errors = data_get($data, 'inventoryAdjustQuantities.userErrors', []);
        if ($errors !== []) {
            throw new \RuntimeException('Shopify inventory adjustment failed: '.collect($errors)->pluck('message')->implode('; '));
        }
        if (! data_get($data, 'inventoryAdjustQuantities.inventoryAdjustmentGroup')) {
            throw new \RuntimeException('Shopify did not return an inventory adjustment confirmation.');
        }
    }
}
