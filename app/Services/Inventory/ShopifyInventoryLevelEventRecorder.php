<?php

namespace App\Services\Inventory;

use App\Models\ShopifyInventoryLevelEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class ShopifyInventoryLevelEventRecorder
{
    /**
     * Shopify's inventory_levels/update webhook carries the new available quantity.
     * It does not name the staff member or the adjustment reason. This stores the
     * payload and does not call Shopify.
     *
     * @param  array<string, mixed>  $payload
     */
    public function store(array $payload, ?string $webhookId): void
    {
        $inventoryItemId = trim((string) ($payload['inventory_item_id'] ?? ''));
        if ($inventoryItemId === '') {
            return;
        }

        $webhookId = trim((string) $webhookId);
        if ($webhookId === '') {
            $webhookId = 'payload-'.substr(hash('sha256', (string) json_encode($payload)), 0, 40);
        }

        try {
            ShopifyInventoryLevelEvent::query()->updateOrCreate(
                ['webhook_id' => $webhookId],
                [
                    'inventory_item_id' => $inventoryItemId,
                    'location_id' => $this->nullable($payload['location_id'] ?? null),
                    'available' => is_numeric($payload['available'] ?? null) ? (int) $payload['available'] : null,
                    'shopify_updated_at' => $this->timestamp($payload['updated_at'] ?? null),
                    'payload' => $payload,
                    'received_at' => now(),
                ],
            );
        } catch (QueryException $exception) {
            Log::warning('Shopify inventory level webhook could not be stored.', [
                'webhook_id' => $webhookId,
                'inventory_item_id' => $inventoryItemId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
