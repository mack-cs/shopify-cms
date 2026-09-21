<?php

namespace App\Services\Procurement;

use App\Jobs\ProcessSupplierReceiptJob;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierReceiptService
{
    public function create(ProcurementSupplierOrderLine $line, mixed $quantity, string $idempotencyKey, ?int $userId = null, string $source = 'cms', ?int $batchId = null, bool $dispatch = true, ?string $grvNumber = null, ?string $outOfSequenceReason = null, ?int $outOfSequenceConfirmedBy = null): ProcurementSupplierReceipt
    {
        if (! is_numeric($quantity) || (int) $quantity <= 0 || (float) $quantity !== (float) (int) $quantity) {
            throw ValidationException::withMessages(['quantity_received' => 'Quantity received must be a positive whole number.']);
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') throw ValidationException::withMessages(['idempotency_key' => 'A receipt request key is required.']);

        $receipt = DB::transaction(function () use ($line, $quantity, $idempotencyKey, $userId, $source, $batchId, $grvNumber, $outOfSequenceReason, $outOfSequenceConfirmedBy): ProcurementSupplierReceipt {
            $existing = ProcurementSupplierReceipt::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $existing;
            $locked = ProcurementSupplierOrderLine::query()->with('order')->lockForUpdate()->findOrFail($line->id);
            $reserved = (int) $locked->receipts()->whereIn('status', ['pending', 'processing', 'succeeded'])->sum('quantity_received');
            $outstanding = (int) $locked->quantity_ordered - $reserved;
            if ((int) $quantity > $outstanding) throw ValidationException::withMessages(['quantity_received' => "Only {$outstanding} unit(s) remain outstanding."]);
            $outOfSequence = $this->outOfSequenceWarningsForLine($locked);
            $reason = trim((string) $outOfSequenceReason);
            if ($outOfSequence->isNotEmpty() && $reason === '') {
                throw ValidationException::withMessages(['out_of_sequence_reason' => $this->warningMessage($locked, $outOfSequence)]);
            }
            $uuid = (string) Str::uuid();
            $inventoryBefore = $locked->variant?->current_available_quantity ?? $locked->variant?->inventory_qty;
            return ProcurementSupplierReceipt::query()->create([
                'uuid' => $uuid, 'grv_number' => $grvNumber ?? $this->nextGrvNumber(), 'supplier_order_line_id' => $locked->id,
                'quantity_received' => (int) $quantity, 'received_at' => now(),
                'inventory_before' => $inventoryBefore,
                'inventory_after' => is_numeric($inventoryBefore) ? ((int) $inventoryBefore + (int) $quantity) : null,
                'idempotency_key' => $idempotencyKey,
                'source' => $source, 'import_batch_id' => $batchId, 'status' => 'pending',
                'post_process_status' => 'pending', 'shopify_reference_uri' => "logistics://shopify-editor/procurement-receipt/{$uuid}",
                'created_by' => $userId,
                'out_of_sequence_confirmed_by' => $outOfSequence->isNotEmpty() ? ($outOfSequenceConfirmedBy ?? $userId) : null,
                'out_of_sequence_confirmed_at' => $outOfSequence->isNotEmpty() ? now() : null,
                'out_of_sequence_reason' => $outOfSequence->isNotEmpty() ? $reason : null,
                'out_of_sequence_audit' => $outOfSequence->isNotEmpty() ? [
                    'receipt_grv' => $grvNumber,
                    'received_order_line_id' => $locked->id,
                    'received_order_id' => $locked->supplier_order_id,
                    'received_order_number' => $locked->order?->order_number,
                    'received_eta' => $locked->eta_date?->toDateString(),
                    'earlier_orders' => $outOfSequence->values()->all(),
                    'reason' => $reason,
                    'confirmed_by' => $outOfSequenceConfirmedBy ?? $userId,
                    'confirmed_at' => now()->toDateTimeString(),
                ] : null,
            ]);
        });
        if ($dispatch && $receipt->wasRecentlyCreated) ProcessSupplierReceiptJob::dispatch($receipt->id)->onQueue('procurement');
        return $receipt;
    }

    public function nextGrvNumber(): string
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

    /**
     * @return array{receipt_count:int,grv_count:int,groups:array<int, array{group:string,grv_number:string,receipt_ids:array<int,int>}>}
     */
    public function backfillMissingGrvNumbers(bool $dryRun = false): array
    {
        if ($dryRun) {
            $receipts = ProcurementSupplierReceipt::query()
                ->whereNull('grv_number')
                ->whereIn('status', ['pending', 'processing', 'succeeded', 'manual_review'])
                ->orderBy('import_batch_id')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
            $nextNumber = (int) (DB::table('procurement_grv_sequences')->value('next_number') ?? 1);
            $assigned = $receipts
                ->groupBy(fn (ProcurementSupplierReceipt $receipt): string => $receipt->import_batch_id !== null
                    ? 'batch:'.$receipt->import_batch_id
                    : 'receipt:'.$receipt->id)
                ->values()
                ->map(function (Collection $groupReceipts) use (&$nextNumber): array {
                    return [
                        'group' => $groupReceipts->first()->import_batch_id !== null
                            ? 'batch:'.$groupReceipts->first()->import_batch_id
                            : 'receipt:'.$groupReceipts->first()->id,
                        'grv_number' => 'GRV-'.str_pad((string) $nextNumber++, 6, '0', STR_PAD_LEFT),
                        'receipt_ids' => $groupReceipts->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                    ];
                })
                ->all();

            return [
                'receipt_count' => $receipts->count(),
                'grv_count' => count($assigned),
                'groups' => $assigned,
            ];
        }

        return DB::transaction(function () use ($dryRun): array {
            $receipts = ProcurementSupplierReceipt::query()
                ->whereNull('grv_number')
                ->whereIn('status', ['pending', 'processing', 'succeeded', 'manual_review'])
                ->orderBy('import_batch_id')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $groups = $receipts
                ->groupBy(fn (ProcurementSupplierReceipt $receipt): string => $receipt->import_batch_id !== null
                    ? 'batch:'.$receipt->import_batch_id
                    : 'receipt:'.$receipt->id);

            $assigned = [];
            foreach ($groups as $group => $groupReceipts) {
                $grvNumber = $this->nextGrvNumber();
                $ids = $groupReceipts->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

                if (! $dryRun) {
                    ProcurementSupplierReceipt::query()
                        ->whereIn('id', $ids)
                        ->whereNull('grv_number')
                        ->update(['grv_number' => $grvNumber]);
                }

                $assigned[] = [
                    'group' => (string) $group,
                    'grv_number' => $grvNumber,
                    'receipt_ids' => $ids,
                ];
            }

            return [
                'receipt_count' => $receipts->count(),
                'grv_count' => count($assigned),
                'groups' => $assigned,
            ];
        });
    }

    public function createFromRow(array $row, string $idempotencyKey, ?int $userId = null, ?int $batchId = null, bool $dispatch = true, ?string $outOfSequenceReason = null): ProcurementSupplierReceipt
    {
        return $this->createFromRowWithGrv($row, $idempotencyKey, $userId, $batchId, $dispatch, outOfSequenceReason: $outOfSequenceReason);
    }

    public function createFromRowWithGrv(array $row, string $idempotencyKey, ?int $userId = null, ?int $batchId = null, bool $dispatch = true, ?string $grvNumber = null, ?string $outOfSequenceReason = null): ProcurementSupplierReceipt
    {
        $order = trim((string) ($row['order_id'] ?? '')); $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        $lines = ProcurementSupplierOrderLine::query()->where('status', 'open')->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])
            ->whereHas('order', fn ($q) => $q->where('order_number', $order))->get();
        if ($lines->count() !== 1) throw ValidationException::withMessages(['sku' => 'No unique open order line matches that Order ID and SKU.']);
        return $this->create($lines->first(), $row['quantity_received'] ?? null, $idempotencyKey, $userId, 'csv', $batchId, $dispatch, $grvNumber, $outOfSequenceReason, $userId);
    }

    public function outOfSequenceWarningsForLine(ProcurementSupplierOrderLine $line): Collection
    {
        if ($line->eta_date === null) {
            return collect();
        }

        return ProcurementSupplierOrderLine::query()
            ->with('order')
            ->whereKeyNot($line->id)
            ->where('status', 'open')
            ->where('variant_id', $line->variant_id)
            ->whereNotNull('eta_date')
            ->whereDate('eta_date', '<', $line->eta_date)
            ->orderBy('eta_date')
            ->get()
            ->map(function (ProcurementSupplierOrderLine $earlier): array {
                $reserved = (int) $earlier->receipts()->whereIn('status', ['pending', 'processing', 'succeeded'])->sum('quantity_received');
                $outstanding = max(0, (int) $earlier->quantity_ordered - $reserved);

                return [
                    'order_line_id' => $earlier->id,
                    'order_id' => $earlier->supplier_order_id,
                    'order_number' => $earlier->order?->order_number ?: 'Legacy order',
                    'sku' => $earlier->sku,
                    'eta' => $earlier->eta_date?->toDateString(),
                    'quantity_outstanding' => $outstanding,
                ];
            })
            ->filter(fn (array $warning): bool => (int) $warning['quantity_outstanding'] > 0)
            ->values();
    }

    public function warningMessage(ProcurementSupplierOrderLine $line, Collection $warnings): string
    {
        $first = $warnings->first();
        $earlierEta = isset($first['eta']) ? date('d F Y', strtotime((string) $first['eta'])) : 'an earlier date';
        $currentEta = $line->eta_date?->format('d F Y') ?? 'no ETA';
        $order = $first['order_number'] ?? 'an earlier pending supplier order';

        return "There is an earlier pending supplier order for this SKU ({$order}) with ETA {$earlierEta}. You are currently receiving stock against an order with ETA {$currentEta}. Please confirm that this receipt is correct and provide a reason before continuing.";
    }
}
