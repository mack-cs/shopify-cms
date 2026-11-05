<?php

namespace App\Services\Shopify;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyInventorySnapshot;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadRecalculation;
use App\Services\Inventory\InventoryAuditContext;
use App\Services\Inventory\InventoryAuditRecorder;

class ShopifyInventoryAdjustmentService
{
    public const SUPPLIER_RECEIPT_MUTATION = <<<'GRAPHQL'
mutation ReceiveProcurementStock($input: InventoryAdjustQuantitiesInput!, $idempotencyKey: String!) {
  inventoryAdjustQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup { createdAt reason referenceDocumentUri }
    userErrors { field message }
  }
}
GRAPHQL;
    public function __construct(
        private readonly ShopifyGraphqlGateway $client,
        private readonly ?InventoryAuditRecorder $audits = null,
    ) {}

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

    /** @return array{location_id:string,available:int,on_hand:int,committed:int} */
    public function currentQuantities(Variant $variant, ?string $locationId = null): array
    {
        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId === '') {
            throw new \RuntimeException("Variant {$variant->id} has no Shopify inventory item ID.");
        }
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        $data = $this->client->graphql(<<<'GRAPHQL'
query ManualStockInventory($id: ID!) {
  inventoryItem(id: $id) {
    inventoryLevels(first: 50) { nodes { location { id } quantities(names: ["available", "committed", "on_hand"]) { name quantity } } }
  }
}
GRAPHQL, ['id' => $inventoryItemId]);
        $level = collect(data_get($data, 'inventoryItem.inventoryLevels.nodes', []))->first(
            fn ($node) => data_get($node, 'location.id') === $locationId
        );
        if (! is_array($level)) {
            throw new \RuntimeException("Shopify inventory was unavailable at location {$locationId}.");
        }
        $quantities = collect($level['quantities'] ?? [])->mapWithKeys(fn ($row) => [$row['name'] => (int) $row['quantity']]);

        return ['location_id' => $locationId, 'available' => (int) $quantities->get('available', 0),
            'on_hand' => (int) $quantities->get('on_hand', 0), 'committed' => (int) $quantities->get('committed', 0)];
    }

    public function reservedQuantity(Variant $variant, ?string $locationId = null): int
    {
        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId === '') {
            throw new \RuntimeException("Variant {$variant->id} has no Shopify inventory item ID.");
        }
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        $data = $this->client->graphql(<<<'GRAPHQL'
query StackReservedQuantity($id: ID!) {
  inventoryItem(id: $id) {
    inventoryLevels(first: 50) {
      nodes { location { id } quantities(names: ["reserved"]) { name quantity } }
    }
  }
}
GRAPHQL, ['id' => $inventoryItemId]);
        $wanted = $this->locationGid($locationId);
        $level = collect(data_get($data, 'inventoryItem.inventoryLevels.nodes', []))->first(
            fn ($node): bool => is_array($node) && $this->locationGid((string) data_get($node, 'location.id')) === $wanted
        );
        if (! is_array($level)) {
            throw new \RuntimeException("Shopify reserved quantity was unavailable at location {$locationId}.");
        }
        $row = collect($level['quantities'] ?? [])->firstWhere('name', 'reserved');

        return max(0, (int) (is_array($row) ? ($row['quantity'] ?? 0) : 0));
    }

    private function locationGid(string $id): string
    {
        $id = trim($id);
        if ($id === '' || str_starts_with($id, 'gid://shopify/')) {
            return $id;
        }

        return ctype_digit($id) ? 'gid://shopify/Location/'.$id : $id;
    }

    /** @return array<string, mixed> */
    public function decreaseOnHand(Variant $variant, int $quantity, string $referenceUri, string $idempotencyKey, ?string $locationId = null, ?int $expectedOnHand = null, ?InventoryAuditContext $audit = null): array
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Inventory deduction quantity must be greater than zero.');
        }
        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        if ($inventoryItemId === '' || $locationId === '') {
            throw new \RuntimeException('The inventory item and location are required.');
        }
        $expectedOnHand ??= $this->currentQuantities($variant, $locationId)['on_hand'];
        $targetOnHand = $expectedOnHand - $quantity;
        if ($targetOnHand < 0) {
            throw new \RuntimeException("Cannot deduct {$quantity}; Shopify On Hand is {$expectedOnHand}.");
        }
        $context = $this->auditContext($audit, $variant, [
            'correlationKey' => $idempotencyKey,
            'workflow' => 'manual_stock',
            'action' => 'manual_deduction',
            'processName' => 'Manual stock job',
            'shopifyReason' => 'correction',
            'inventoryState' => 'on_hand',
            'delta' => -$quantity,
            'quantityBefore' => $expectedOnHand,
            'quantityAfter' => $targetOnHand,
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'referenceDocumentUri' => $referenceUri,
        ]);

        return $this->audited($context, function () use ($inventoryItemId, $locationId, $targetOnHand, $expectedOnHand, $referenceUri, $idempotencyKey): array {
            $data = $this->client->graphql(<<<'GRAPHQL'
mutation DeductManualStock($input: InventorySetQuantitiesInput!, $idempotencyKey: String!) {
  inventorySetQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup { createdAt reason referenceDocumentUri changes { name delta quantityAfterChange } }
    userErrors { code field message }
  }
}
GRAPHQL, ['input' => ['name' => 'on_hand', 'reason' => 'correction', 'referenceDocumentUri' => $referenceUri,
                'quantities' => [['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId,
                    'quantity' => $targetOnHand, 'changeFromQuantity' => $expectedOnHand]]],
                'idempotencyKey' => $idempotencyKey]);

            return $this->confirmedGroup($data, 'inventorySetQuantities');
        });
    }

    public function increaseAvailable(Variant $variant, int $quantity, string $referenceUri, ?string $locationId = null, ?InventoryAuditContext $audit = null): void
    {
        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId === '') {
            throw new \RuntimeException("Variant {$variant->id} has no Shopify inventory item ID.");
        }
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        $context = $this->auditContext($audit, $variant, [
            'correlationKey' => $referenceUri,
            'workflow' => 'supplier_receipt',
            'action' => 'inventory_received',
            'processName' => 'Supplier receipt job',
            'explanation' => 'Supplier stock received',
            'shopifyReason' => 'received',
            'inventoryState' => 'available',
            'delta' => $quantity,
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'referenceDocumentUri' => $referenceUri,
        ]);

        $idempotencyKey = hash('sha256', 'inventory-adjust:'.$referenceUri);
        $this->audited($context, function () use ($inventoryItemId, $locationId, $quantity, $referenceUri, $idempotencyKey): array {
            $data = $this->client->graphql(self::SUPPLIER_RECEIPT_MUTATION, [
                'input' => [
                    'name' => 'available', 'reason' => 'received', 'referenceDocumentUri' => $referenceUri,
                    'changes' => [['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'delta' => $quantity]],
                ],
                'idempotencyKey' => $idempotencyKey,
            ]);
            $errors = data_get($data, 'inventoryAdjustQuantities.userErrors', []);
            if ($errors !== []) {
                throw new \RuntimeException('Shopify inventory adjustment failed: '.collect($errors)->pluck('message')->implode('; '));
            }
            $group = data_get($data, 'inventoryAdjustQuantities.inventoryAdjustmentGroup');
            if (! is_array($group)) {
                throw new \RuntimeException('Shopify did not return an inventory adjustment confirmation.');
            }

            return $group;
        });
    }

    /**
     * Restore additional physical units after Shopify restocks the original order unit.
     * The caller must pass only the CMS additional quantity, never Shopify's original unit.
     *
     * @return array<string, mixed>
     */
    public function restockAvailable(
        Variant $variant,
        int $quantity,
        string $referenceUri,
        string $idempotencyKey,
        ?string $locationId = null,
        ?InventoryAuditContext $audit = null,
        string $inventoryState = 'available',
        ?string $ledgerDocumentUri = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Restock quantity must be greater than zero.');
        }
        if (! in_array($inventoryState, ['available', 'safety_stock'], true) || ($inventoryState === 'safety_stock' && ! $ledgerDocumentUri)) {
            throw new \InvalidArgumentException('A restock requires a supported state and an owned safety-stock ledger.');
        }
        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId === '') {
            throw new \RuntimeException("Variant {$variant->id} has no Shopify inventory item ID.");
        }
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        $context = $this->auditContext($audit, $variant, [
            'correlationKey' => $idempotencyKey,
            'workflow' => 'engraved_bead_restock',
            'action' => 'restock',
            'processName' => 'Order processing',
            'explanation' => 'Additional engraved beads were restored because the returned units were restocked.',
            'shopifyReason' => 'restock',
            'inventoryState' => $inventoryState,
            'delta' => $quantity,
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'referenceDocumentUri' => $referenceUri,
        ]);

        return $this->audited($context, function () use ($inventoryItemId, $locationId, $quantity, $referenceUri, $idempotencyKey, $inventoryState, $ledgerDocumentUri): array {
            $data = $this->client->graphql(<<<'GRAPHQL'
mutation RestockEngravedBeadInventory($input: InventoryAdjustQuantitiesInput!, $idempotencyKey: String!) {
  inventoryAdjustQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup { createdAt reason referenceDocumentUri changes { name delta quantityAfterChange } }
    userErrors { field message }
  }
}
GRAPHQL, [
                'input' => [
                    'name' => $inventoryState,
                    'reason' => 'restock',
                    'referenceDocumentUri' => $referenceUri,
                    'changes' => [[
                        'inventoryItemId' => $inventoryItemId,
                        'locationId' => $locationId,
                        'delta' => $quantity,
                        ...($inventoryState === 'safety_stock' ? ['ledgerDocumentUri' => $ledgerDocumentUri] : []),
                    ]],
                ],
                'idempotencyKey' => $idempotencyKey,
            ]);

            return $this->confirmedGroup($data, 'inventoryAdjustQuantities');
        });
    }

    /**
     * Compare-and-set on-hand. A retry or a stale expected quantity is rejected by Shopify
     * instead of overwriting newer stock.
     *
     * @return array<string, mixed>
     */
    public function setOnHand(
        Variant $variant,
        int $quantity,
        int $expectedOnHand,
        string $referenceUri,
        string $idempotencyKey,
        ?string $locationId = null,
        ?InventoryAuditContext $audit = null,
    ): array {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('On-hand quantity cannot be negative.');
        }
        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        if ($inventoryItemId === '' || $locationId === '') {
            throw new \RuntimeException('The inventory item and location are required.');
        }
        $context = $this->auditContext($audit, $variant, [
            'correlationKey' => $idempotencyKey,
            'workflow' => 'product_launch',
            'action' => 'launch_inventory',
            'processName' => 'Product launch',
            'explanation' => 'Approved CMS inventory was applied while launching the product.',
            'shopifyReason' => 'correction',
            'inventoryState' => 'on_hand',
            'quantityBefore' => $expectedOnHand,
            'quantityAfter' => $quantity,
            'delta' => $quantity - $expectedOnHand,
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'referenceDocumentUri' => $referenceUri,
        ]);

        return $this->audited($context, function () use ($inventoryItemId, $locationId, $quantity, $expectedOnHand, $referenceUri, $idempotencyKey): array {
            $data = $this->client->graphql(<<<'GRAPHQL'
mutation LaunchSetOnHand($input: InventorySetQuantitiesInput!, $idempotencyKey: String!) {
  inventorySetQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup { createdAt reason referenceDocumentUri changes { name delta quantityAfterChange } }
    userErrors { code field message }
  }
}
GRAPHQL, [
                'input' => [
                    'name' => 'on_hand',
                    'reason' => 'correction',
                    'referenceDocumentUri' => $referenceUri,
                    'quantities' => [[
                        'inventoryItemId' => $inventoryItemId,
                        'locationId' => $locationId,
                        'quantity' => $quantity,
                        'changeFromQuantity' => $expectedOnHand,
                    ]],
                ],
                'idempotencyKey' => $idempotencyKey,
            ]);

            return $this->confirmedGroup($data, 'inventorySetQuantities');
        });
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
        ?InventoryAuditContext $audit = null,
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
        $context = $this->auditContext($audit, $variant, [
            'correlationKey' => $idempotencyKey,
            'workflow' => 'unspecified',
            'action' => 'available_decreased',
            'shopifyReason' => 'correction',
            'inventoryState' => 'available',
            'delta' => -$quantity,
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'referenceDocumentUri' => $referenceUri,
        ]);

        return $this->audited($context, function () use ($inventoryItemId, $locationId, $quantity, $referenceUri, $idempotencyKey): array {
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
        });
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
        ?InventoryAuditContext $audit = null,
        ?string $sourceLedgerDocumentUri = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Inventory movement quantity must be greater than zero.');
        }

        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        if ($inventoryItemId === '' || $locationId === '') {
            throw new \RuntimeException('The component inventory item and location are required.');
        }
        $context = $this->auditContext($audit, $variant, [
            'correlationKey' => $idempotencyKey,
            'workflow' => $to === 'reserved' ? 'stack_reserve' : 'stack_release',
            'action' => $to === 'reserved' ? 'reserve' : 'release',
            'processName' => 'Order processing',
            'shopifyReason' => $reason,
            'inventoryState' => $to,
            'delta' => $to === 'reserved' ? $quantity : -$quantity,
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'referenceDocumentUri' => $referenceUri,
        ]);

        $terminal = fn (string $name): array => array_filter([
            'locationId' => $locationId,
            'name' => $name,
            'ledgerDocumentUri' => $name === 'available' ? null : ($name === $from ? ($sourceLedgerDocumentUri ?? $ledgerDocumentUri) : $ledgerDocumentUri),
        ], fn (mixed $value): bool => $value !== null);

        $data = $this->graphqlAudited($context, <<<'GRAPHQL'
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

        return $this->audited($context, fn (): array => $this->confirmedGroup($data, 'inventoryMoveQuantities'));
    }

    /** Remove physically consumed stock from reserved; available remains unchanged. @return array<string, mixed> */
    public function consumeReserved(
        Variant $variant,
        int $quantity,
        string $referenceUri,
        string $ledgerDocumentUri,
        string $idempotencyKey,
        ?string $locationId = null,
        ?InventoryAuditContext $audit = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Consumed inventory quantity must be greater than zero.');
        }

        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        $locationId = trim((string) ($locationId ?? $this->resolveLocationId($variant)));
        if ($inventoryItemId === '' || $locationId === '') {
            throw new \RuntimeException('The component inventory item and location are required.');
        }
        $context = $this->auditContext($audit, $variant, [
            'correlationKey' => $idempotencyKey,
            'workflow' => 'stack_consume',
            'action' => 'consume',
            'processName' => 'Order processing',
            'explanation' => 'Reserved component inventory was consumed when the stack was fulfilled.',
            'shopifyReason' => 'correction',
            'inventoryState' => 'reserved',
            'delta' => -$quantity,
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'referenceDocumentUri' => $referenceUri,
        ]);

        $data = $this->graphqlAudited($context, <<<'GRAPHQL'
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

        return $this->audited($context, fn (): array => $this->confirmedGroup($data, 'inventoryAdjustQuantities'));
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

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function auditContext(?InventoryAuditContext $audit, Variant $variant, array $defaults): InventoryAuditContext
    {
        $defaults += [
            'variantId' => $variant->id ? (int) $variant->id : null,
            'productId' => $variant->product_id ? (int) $variant->product_id : null,
            'sku' => trim((string) $variant->sku) ?: null,
            'actorType' => 'system',
            'execution' => 'queue',
        ];
        $base = $audit ?? new InventoryAuditContext(
            correlationKey: (string) $defaults['correlationKey'],
            workflow: (string) $defaults['workflow'],
            action: (string) $defaults['action'],
        );

        return $base->withDefaults($defaults);
    }

    /**
     * @param  callable():array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function audited(InventoryAuditContext $context, callable $callback): array
    {
        try {
            $group = $callback();
            $this->auditRecorder()->succeeded($context, $group);
            if ($context->inventoryItemId) {
                app(EngravedBeadRecalculation::class)->inventoryItem($context->inventoryItemId);
            }

            return $group;
        } catch (\Throwable $exception) {
            $this->auditRecorder()->failed($context, $exception->getMessage());

            throw $exception;
        }
    }

    private function auditRecorder(): InventoryAuditRecorder
    {
        return $this->audits ?? app(InventoryAuditRecorder::class);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphqlAudited(InventoryAuditContext $context, string $query, array $variables): array
    {
        try {
            return $this->client->graphql($query, $variables);
        } catch (\Throwable $exception) {
            $this->auditRecorder()->failed($context, $exception->getMessage());

            throw $exception;
        }
    }
}
