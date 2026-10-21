<?php

namespace App\Services\Inventory;

final class InventoryAuditContext
{
    public function __construct(
        public readonly string $correlationKey,
        public readonly string $workflow,
        public readonly string $action,
        public readonly string $actorType = 'system',
        public readonly string $execution = 'queue',
        public readonly ?int $userId = null,
        public readonly ?string $processName = null,
        public readonly ?string $explanation = null,
        public readonly ?string $shopifyReason = null,
        public readonly ?string $inventoryState = null,
        public readonly ?int $delta = null,
        public readonly ?int $quantityBefore = null,
        public readonly ?int $quantityAfter = null,
        public readonly ?int $productId = null,
        public readonly ?int $variantId = null,
        public readonly ?string $sku = null,
        public readonly ?string $inventoryItemId = null,
        public readonly ?string $locationId = null,
        public readonly ?string $referenceDocumentUri = null,
        public readonly ?string $relatedType = null,
        public readonly ?int $relatedId = null,
    ) {}

    /**
     * @param array<string, mixed> $defaults
     */
    public function withDefaults(array $defaults): self
    {
        $values = [
            'correlationKey' => $this->correlationKey,
            'workflow' => $this->workflow,
            'action' => $this->action,
            'actorType' => $this->actorType,
            'execution' => $this->execution,
            'userId' => $this->userId,
            'processName' => $this->processName,
            'explanation' => $this->explanation,
            'shopifyReason' => $this->shopifyReason,
            'inventoryState' => $this->inventoryState,
            'delta' => $this->delta,
            'quantityBefore' => $this->quantityBefore,
            'quantityAfter' => $this->quantityAfter,
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'sku' => $this->sku,
            'inventoryItemId' => $this->inventoryItemId,
            'locationId' => $this->locationId,
            'referenceDocumentUri' => $this->referenceDocumentUri,
            'relatedType' => $this->relatedType,
            'relatedId' => $this->relatedId,
        ];

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '') {
                $values[$key] = $value;
            }
        }

        return new self(...$values);
    }
}
