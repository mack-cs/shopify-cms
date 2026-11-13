<?php

namespace App\Services;

use App\Models\InventoryWriteIntent;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

final class InventoryWriteIntents
{
    public function key(Variant $variant, array $payload, ?string $batch): string
    {
        return hash('sha256', json_encode([$variant->id, $batch, $payload, (int) $variant->inventory_write_version]));
    }

    public function uncertainOutcome(Variant $variant): bool
    {
        return InventoryWriteIntent::query()
            ->where('variant_id', $variant->id)
            ->where('status', 'started')
            ->exists();
    }

    public function claim(Variant $variant, array $payload, ?string $batch): InventoryWriteIntent
    {
        return DB::transaction(function () use ($variant, $payload, $batch) {
            $current = Variant::whereKey($variant->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->inventory_write_version !== (int) $variant->inventory_write_version) {
                throw new \RuntimeException('Inventory intent changed before dispatch. Review the current version.');
            }
            $key = $this->key($variant, $payload, $batch);
            $existing = InventoryWriteIntent::where('intent_key', $key)->first();
            if (InventoryWriteIntent::where('variant_id', $variant->id)->where('status', 'started')->exists()) {
                throw new \RuntimeException('An inventory mutation has an uncertain outcome. Operator reconciliation is required; automatic replay is blocked.');
            }
            if ($existing?->status === 'confirmed') {
                return $existing;
            }

            return InventoryWriteIntent::updateOrCreate(['intent_key' => $key], [
                'variant_id' => $variant->id, 'payload' => $payload, 'status' => 'started', 'error' => null,
            ]);
        });
    }
}
