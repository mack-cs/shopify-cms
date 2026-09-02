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

    /**
     * @return array<string, mixed>
     */
    public function decreaseAvailable(
        Variant $variant,
        int $quantity,
        string $referenceUri,
        string $idempotencyKey,
        ?string $locationId = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Inventory deduction quantity must be greater than zero.');
        }

        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId === '') {
            throw new \RuntimeException("Variant {$variant->id} has no Shopify inventory item ID.");
        }

        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        if ($locationId === '') {
            throw new \RuntimeException('No Shopify inventory location could be resolved.');
        }

        $data = $this->client->graphql(<<<'GRAPHQL'
mutation DeductStackComponentInventory($input: InventoryAdjustQuantitiesInput!, $idempotencyKey: String!) {
  inventoryAdjustQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup {
      createdAt
      reason
      referenceDocumentUri
      changes { name delta quantityAfterChange }
    }
    userErrors { field message }
  }
}
GRAPHQL, [
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'referenceDocumentUri' => $referenceUri,
                'changes' => [[
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'delta' => -$quantity,
                ]],
            ],
            'idempotencyKey' => $idempotencyKey,
        ]);

        $errors = data_get($data, 'inventoryAdjustQuantities.userErrors', []);
        if ($errors !== []) {
            throw new \RuntimeException(
                'Shopify inventory adjustment failed: '.collect($errors)->pluck('message')->implode('; ')
            );
        }

        $group = data_get($data, 'inventoryAdjustQuantities.inventoryAdjustmentGroup');
        if (! is_array($group)) {
            throw new \RuntimeException('Shopify did not return an inventory adjustment confirmation.');
        }

        return $group;
    }

    /** @return array<string, mixed> */
    public function moveQuantity(
        Variant $variant,
        int $quantity,
        string $from,
        string $to,
        string $reason,
        string $referenceUri,
        string $ledgerDocumentUri,
        string $idempotencyKey,
        ?string $locationId = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Inventory movement quantity must be greater than zero.');
        }

        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        if ($inventoryItemId === '' || $locationId === '') {
            throw new \RuntimeException('The component inventory item and location are required.');
        }

        $terminal = fn (string $name): array => array_filter([
            'locationId' => $locationId,
            'name' => $name,
            'ledgerDocumentUri' => $name === 'available' ? null : $ledgerDocumentUri,
        ], fn (mixed $value): bool => $value !== null);

        $data = $this->client->graphql(<<<'GRAPHQL'
mutation MoveStackComponentInventory($input: InventoryMoveQuantitiesInput!, $idempotencyKey: String!) {
  inventoryMoveQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup {
      createdAt reason referenceDocumentUri
      changes { name delta ledgerDocumentUri }
    }
    userErrors { field message code }
  }
}
GRAPHQL, [
            'input' => [
                'reason' => $reason,
                'referenceDocumentUri' => $referenceUri,
                'changes' => [[
                    'quantity' => $quantity,
                    'inventoryItemId' => $inventoryItemId,
                    'from' => $terminal($from),
                    'to' => $terminal($to),
                ]],
            ],
            'idempotencyKey' => $idempotencyKey,
        ]);

        return $this->confirmedGroup($data, 'inventoryMoveQuantities');
    }

    /** Remove physically consumed stock from reserved; available remains unchanged. @return array<string, mixed> */
    public function consumeReserved(
        Variant $variant,
        int $quantity,
        string $referenceUri,
        string $ledgerDocumentUri,
        string $idempotencyKey,
        ?string $locationId = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Consumed inventory quantity must be greater than zero.');
        }

        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        if ($inventoryItemId === '' || $locationId === '') {
            throw new \RuntimeException('The component inventory item and location are required.');
        }

        $data = $this->client->graphql(<<<'GRAPHQL'
mutation ConsumeReservedStackInventory($input: InventoryAdjustQuantitiesInput!, $idempotencyKey: String!) {
  inventoryAdjustQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup {
      createdAt reason referenceDocumentUri
      changes { name delta quantityAfterChange ledgerDocumentUri }
    }
    userErrors { field message }
  }
}
GRAPHQL, [
            'input' => [
                'name' => 'reserved',
                'reason' => 'correction',
                'referenceDocumentUri' => $referenceUri,
                'changes' => [[
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'delta' => -$quantity,
                    'ledgerDocumentUri' => $ledgerDocumentUri,
                ]],
            ],
            'idempotencyKey' => $idempotencyKey,
        ]);

        return $this->confirmedGroup($data, 'inventoryAdjustQuantities');
    }

    /** @return array<string, mixed> */
    private function confirmedGroup(array $data, string $mutation): array
    {
        $errors = data_get($data, "{$mutation}.userErrors", []);
        if ($errors !== []) {
            throw new \RuntimeException(
                'Shopify inventory operation failed: '.collect($errors)->pluck('message')->implode('; ')
            );
        }

        $group = data_get($data, "{$mutation}.inventoryAdjustmentGroup");
        if (! is_array($group)) {
            throw new \RuntimeException('Shopify did not return an inventory operation confirmation.');
        }

        return $group;
    }
}
