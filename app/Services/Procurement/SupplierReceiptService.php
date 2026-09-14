<?php

namespace App\Services\Procurement;

use App\Jobs\ProcessSupplierReceiptJob;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierReceiptService
{
    public function create(ProcurementSupplierOrderLine $line, mixed $quantity, string $idempotencyKey, ?int $userId = null, string $source = 'cms', ?int $batchId = null, bool $dispatch = true): ProcurementSupplierReceipt
    {
        if (! is_numeric($quantity) || (int) $quantity <= 0 || (float) $quantity !== (float) (int) $quantity) {
            throw ValidationException::withMessages(['quantity_received' => 'Quantity received must be a positive whole number.']);
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') throw ValidationException::withMessages(['idempotency_key' => 'A receipt request key is required.']);

        $receipt = DB::transaction(function () use ($line, $quantity, $idempotencyKey, $userId, $source, $batchId): ProcurementSupplierReceipt {
            $existing = ProcurementSupplierReceipt::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $existing;
            $locked = ProcurementSupplierOrderLine::query()->lockForUpdate()->findOrFail($line->id);
            $reserved = (int) $locked->receipts()->whereIn('status', ['pending', 'processing', 'succeeded'])->sum('quantity_received');
            $outstanding = (int) $locked->quantity_ordered - $reserved;
            if ((int) $quantity > $outstanding) throw ValidationException::withMessages(['quantity_received' => "Only {$outstanding} unit(s) remain outstanding."]);
            $uuid = (string) Str::uuid();
            $inventoryBefore = $locked->variant?->current_available_quantity ?? $locked->variant?->inventory_qty;
            return ProcurementSupplierReceipt::query()->create([
                'uuid' => $uuid, 'grv_number' => $this->nextGrvNumber(), 'supplier_order_line_id' => $locked->id,
                'quantity_received' => (int) $quantity, 'received_at' => now(),
                'inventory_before' => $inventoryBefore,
                'inventory_after' => is_numeric($inventoryBefore) ? ((int) $inventoryBefore + (int) $quantity) : null,
                'idempotency_key' => $idempotencyKey,
                'source' => $source, 'import_batch_id' => $batchId, 'status' => 'pending',
                'post_process_status' => 'pending', 'shopify_reference_uri' => "logistics://shopify-editor/procurement-receipt/{$uuid}",
                'created_by' => $userId,
            ]);
        });
        if ($dispatch && $receipt->wasRecentlyCreated) ProcessSupplierReceiptJob::dispatch($receipt->id)->onQueue('procurement');
        return $receipt;
    }

    private function nextGrvNumber(): string
    {
        $sequence = DB::table('procurement_grv_sequences')->lockForUpdate()->first();
        if ($sequence === null) {
            DB::table('procurement_grv_sequences')->insert([
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = DB::table('procurement_grv_sequences')->lockForUpdate()->first();
        }
        $number = (int) $sequence->next_number;
        DB::table('procurement_grv_sequences')->where('id', $sequence->id)->update([
            'next_number' => $number + 1,
            'updated_at' => now(),
        ]);

        return 'GRV-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public function createFromRow(array $row, string $idempotencyKey, ?int $userId = null, ?int $batchId = null, bool $dispatch = true): ProcurementSupplierReceipt
    {
        $order = trim((string) ($row['order_id'] ?? '')); $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        $lines = ProcurementSupplierOrderLine::query()->where('status', 'open')->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])
            ->whereHas('order', fn ($q) => $q->where('order_number', $order))->get();
        if ($lines->count() !== 1) throw ValidationException::withMessages(['sku' => 'No unique open order line matches that Order ID and SKU.']);
        return $this->create($lines->first(), $row['quantity_received'] ?? null, $idempotencyKey, $userId, 'csv', $batchId, $dispatch);
    }
}
