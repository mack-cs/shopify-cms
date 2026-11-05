<?php

namespace App\Services\Procurement;

use App\Jobs\ProcessSupplierReceiptJob;
use App\Jobs\PublishOperationalProcurementRowsJob;
use App\Models\NewProductDraft;
use App\Models\ProcurementSupplierImportBatch;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierOrderCsvService
{
    public function __construct(private readonly SupplierOrderService $orders, private readonly SupplierReceiptService $receipts) {}

    public function preview(string $path, string $type, ?int $userId = null, ?string $filename = null): ProcurementSupplierImportBatch
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw ValidationException::withMessages(['file' => 'The CSV could not be read.']);
        }

        return $this->previewContents($contents, $type, false, $userId, $filename);
    }

    public function previewPastedOrder(string $contents, ?int $userId = null): ProcurementSupplierImportBatch
    {
        return $this->previewPasted($contents, 'order', $userId);
    }

    public function previewPastedReceipt(string $contents, ?int $userId = null): ProcurementSupplierImportBatch
    {
        return $this->previewPasted($contents, 'receipt', $userId);
    }

    private function previewPasted(string $contents, string $type, ?int $userId = null): ProcurementSupplierImportBatch
    {
        return $this->previewContents($contents, $type, true, $userId, 'pasted-'.$type.'.tsv');
    }

    private function previewContents(string $contents, string $type, bool $paste, ?int $userId, ?string $filename): ProcurementSupplierImportBatch
    {
        if (! in_array($type, ['order', 'receipt'], true)) {
            throw new \InvalidArgumentException('Unsupported supplier import type.');
        }
        $hash = hash('sha256', $contents);
        $existing = ProcurementSupplierImportBatch::where('type', $type)->where('file_hash', $hash)->first();
        if ($existing?->status === 'completed') {
            return $existing;
        }
        $parser = app(SupplierImportPreview::class);
        $rows = $parser->validate($parser->parse($contents, $type, $paste, $this->header(...)), $type, $this->validateRow(...));
        $errors = [];
        foreach ($rows as $row) {
            if (! $row['_valid']) {
                $errors[(string) $row['_row']] = $row['_errors'];
            }
        }
        $batch = $existing ?? new ProcurementSupplierImportBatch;
        $batch->fill(['uuid' => $existing?->uuid ?? (string) Str::uuid(), 'type' => $type, 'original_filename' => $filename, 'file_hash' => $hash,
            'status' => 'previewed', 'preview_rows' => $rows, 'errors' => $errors ?: null, 'valid_count' => count($rows) - count($errors), 'invalid_count' => count($errors),
            'created_by' => $userId, 'confirmed_at' => null, 'completed_at' => null])->save();

        return $batch->fresh();
    }

    public function rejectedCsv(ProcurementSupplierImportBatch $batch): string
    {
        $rows = collect($batch->preview_rows)->filter(fn ($row) => ! ($row['_valid'] ?? false));
        $headers = (array) ($rows->first()['_headers'] ?? ($batch->type === 'order' ? ['SKU', 'Order ID', 'Quantity Ordered', 'ETA Date'] : ['Order ID', 'SKU', 'Quantity Received']));
        $valueCount = $rows->max(fn ($row) => count($row['_values'] ?? [])) ?? 0;
        while (count($headers) < $valueCount) {
            $headers[] = 'Extra column '.(count($headers) + 1);
        }
        $indices = array_keys(array_filter($headers, fn ($header) => ! in_array($this->header($header), ['original_row', 'error_reason'], true)));
        $labels = array_map(fn ($index) => $headers[$index], $indices);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, [...$labels, 'Original Row', 'Error Reason']);
        foreach ($rows as $row) {
            $values = $row['_values'] ?? array_map(fn ($header) => $row[$this->header($header)] ?? '', $headers);
            $values = array_map(fn ($index) => $values[$index] ?? '', $indices);
            fputcsv($stream, [...$values, $row['_source_row'] ?? $row['_row'], implode('; ', (array) ($row['_errors'] ?? data_get($batch->errors, (string) $row['_row'], [])))]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public function confirm(string $uuid, ?int $userId = null, bool $dispatchReceipts = true, ?string $outOfSequenceReason = null, bool $acceptValidRows = false): ProcurementSupplierImportBatch
    {
        $batch = ProcurementSupplierImportBatch::where('uuid', $uuid)->firstOrFail();
        $receiptIds = [];
        $reviewChanged = false;
        DB::transaction(function () use ($batch, $userId, $outOfSequenceReason, $acceptValidRows, &$receiptIds, &$reviewChanged): void {
            $locked = ProcurementSupplierImportBatch::lockForUpdate()->findOrFail($batch->id);
            if ($locked->status === 'completed') {
                return;
            }
            // Lock shared purchase orders/lines before revalidation and keep those locks through staging.
            $orderIds = ProcurementSupplierOrder::whereIn('order_number', collect($locked->preview_rows)->pluck('order_id')->all())->orderBy('id')->lockForUpdate()->pluck('id');
            ProcurementSupplierOrderLine::whereIn('supplier_order_id', $orderIds)->orderBy('id')->lockForUpdate()->get();
            $rows = app(SupplierImportPreview::class)->validate($locked->preview_rows, $locked->type, $this->validateRow(...));
            $errors = [];
            foreach ($rows as $index => $row) {
                if (! $row['_valid']) {
                    $errors[(string) $row['_row']] = $row['_errors'];
                }
                $old = $locked->preview_rows[$index];
                if ((bool) ($old['_valid'] ?? false) !== $row['_valid'] || (array) ($old['_errors'] ?? data_get($locked->errors, (string) $row['_row'], [])) !== $row['_errors']) {
                    $reviewChanged = true;
                }
            }
            $locked->update(['preview_rows' => $rows, 'errors' => $errors ?: null, 'valid_count' => count($rows) - count($errors), 'invalid_count' => count($errors)]);
            if ($reviewChanged) {
                return;
            } // Commit the new explanations, but nothing else. Require another explicit review.
            if ($errors !== [] && ! $acceptValidRows) {
                throw ValidationException::withMessages(['batch_uuid' => 'Review the rejected rows and explicitly choose to save only valid rows.']);
            }
            $accepted = array_filter($rows, fn ($row) => $row['_valid']);
            if ($accepted === []) {
                throw ValidationException::withMessages(['batch_uuid' => 'There are no valid rows to save. Download the rejected rows, correct them and upload again.']);
            }
            if ($locked->type === 'receipt' && $this->outOfSequenceWarnings($locked)->isNotEmpty() && trim((string) $outOfSequenceReason) === '') {
                throw ValidationException::withMessages(['out_of_sequence_reason' => 'Confirm the out-of-sequence receipt warning and provide a reason before continuing.']);
            }
            $locked->update(['status' => 'processing', 'confirmed_at' => now()]);
            $grv = $locked->type === 'receipt' ? $this->receipts->nextGrvNumber() : null;
            foreach ($accepted as $index => $row) {
                if ($locked->type === 'order') {
                    $record = $this->orders->createFromRow($row, $userId, 'csv');
                } else {
                    $record = $this->receipts->createFromRowWithGrv($row, "csv:{$locked->uuid}:".($index + 1), $userId, $locked->id, false, $grv, $outOfSequenceReason);
                    $receiptIds[] = $record->id;
                }
                $rows[$index]['_saved'] = true;
                $rows[$index]['_saved_id'] = $record->id;
            }
            $locked->update(['preview_rows' => $rows, 'status' => 'completed', 'completed_at' => now()]);
        });
        if ($reviewChanged) {
            $messages = collect($batch->fresh()->errors ?? [])->flatten()->unique()->implode(' ');
            throw ValidationException::withMessages(['batch_uuid' => 'The preview changed. Review it again before saving. '.$messages]);
        }
        if ($dispatchReceipts) {
            foreach (array_unique($receiptIds) as $id) {
                if ($userId !== null) {
                    $this->receipts->authorizePush(ProcurementSupplierReceipt::findOrFail($id), $userId);
                } else {
                    ProcessSupplierReceiptJob::dispatch($id);
                }
            }
        }
        $batch->refresh();
        if ($batch->type === 'order') {
            $this->publishOrderRows($batch);
        }

        return $batch;
    }

    public function outOfSequenceWarnings(ProcurementSupplierImportBatch $batch): Collection
    {
        if ($batch->type !== 'receipt') {
            return collect();
        }

        return collect($batch->preview_rows)
            ->filter(fn (array $row): bool => (bool) ($row['_valid'] ?? false))
            ->flatMap(function (array $row): array {
                $line = $this->receiptLineForRow($row);
                if (! $line instanceof ProcurementSupplierOrderLine) {
                    return [];
                }

                return $this->receipts->outOfSequenceWarningsForLine($line)
                    ->map(fn (array $warning): array => [
                        'sku' => $line->sku,
                        'receiving_order_line_id' => $line->id,
                        'receiving_order_number' => $line->order?->order_number ?: 'Legacy order',
                        'receiving_eta' => $line->eta_date?->toDateString(),
                        'earlier_order_number' => $warning['order_number'] ?? 'Legacy order',
                        'earlier_eta' => $warning['eta'] ?? null,
                        'earlier_quantity_outstanding' => $warning['quantity_outstanding'] ?? null,
                    ])
                    ->all();
            })
            ->unique(fn (array $warning): string => implode('|', [
                $warning['sku'] ?? '',
                $warning['receiving_order_line_id'] ?? '',
                $warning['earlier_order_number'] ?? '',
                $warning['earlier_eta'] ?? '',
            ]))
            ->values();
    }

    private function receiptLineForRow(array $row): ?ProcurementSupplierOrderLine
    {
        $order = trim((string) ($row['order_id'] ?? ''));
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        if ($order === '' || $sku === '') {
            return null;
        }

        $lines = ProcurementSupplierOrderLine::query()
            ->with('order')
            ->where('status', 'open')
            ->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])
            ->whereHas('order', fn ($query) => $query->where('order_number', $order))
            ->get();

        return $lines->count() === 1 ? $lines->first() : null;
    }

    private function publishOrderRows(ProcurementSupplierImportBatch $batch): void
    {
        $skus = collect($batch->preview_rows)->filter(fn ($row) => (bool) ($row['_saved'] ?? $row['_valid'] ?? false))->pluck('sku')->map(fn ($sku) => strtoupper(trim((string) $sku)))->unique();
        $ids = Variant::query()->active()->whereIn(DB::raw('UPPER(TRIM(sku))'), $skus)->pluck('id')->all();
        if ($ids !== []) {
            PublishOperationalProcurementRowsJob::dispatch($ids, includeHumanInputs: true)
                ->onQueue((string) config('procurement.queue', 'procurement'));
        }
    }

    private function validateRow(array $row, string $type): array
    {
        $errors = [];
        $labels = $type === 'order' ? ['sku' => 'SKU', 'order_id' => 'Order ID', 'quantity_ordered' => 'Quantity ordered', 'eta' => 'ETA Date'] : ['sku' => 'SKU', 'order_id' => 'Order ID', 'quantity_received' => 'Quantity received'];
        foreach ($labels as $key => $label) {
            if (trim((string) ($row[$key] ?? '')) === '') {
                $errors[] = $label.' is required.';
            }
        }
        $quantity = $row[$type === 'order' ? 'quantity_ordered' : 'quantity_received'] ?? '';
        if (! ctype_digit((string) $quantity) || (int) $quantity <= 0) {
            $errors[] = 'Quantity must be a positive whole number.';
        }
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        $order = trim((string) ($row['order_id'] ?? ''));
        if ($type === 'order') {
            if ($sku !== '' && $this->skuMatchCount($sku, 'order') !== 1) {
                $errors[] = "SKU {$sku} must identify one eligible non-stack product or draft. Check the SKU and synchronized catalog mapping.";
            } elseif ($sku !== '') {
                $variant = Variant::active()->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])->whereHas('product', fn ($q) => $q->procurementOrderEligible())->first();
                if ($variant && app(SupplierReceivingEligibility::class)->isBead($variant)) {
                    try {
                        app(SupplierReceivingEligibility::class)->assertVariant($variant);
                    } catch (ValidationException $e) {
                        $errors = array_merge($errors, collect($e->errors())->flatten()->all());
                    }
                }
            }
            if (trim((string) ($row['eta'] ?? '')) !== '') {
                try {
                    $eta = trim((string) $row['eta']);
                    if (! preg_match('/^(\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})$/', $eta)) {
                        throw new \InvalidArgumentException;
                    }
                    str_contains($eta, '/') ? Carbon::createFromFormat('!d/m/Y', $eta) : Carbon::createFromFormat('!Y-m-d', $eta);
                    $dateErrors = \DateTimeImmutable::getLastErrors();
                    if (is_array($dateErrors) && (($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0)) {
                        throw new \InvalidArgumentException;
                    }
                } catch (\Throwable) {
                    $errors[] = 'ETA Date is not a valid date. Use DD/MM/YYYY or YYYY-MM-DD.';
                }
            }
            if ($sku !== '' && $order !== '' && ProcurementSupplierOrderLine::whereRaw('UPPER(TRIM(sku)) = ?', [$sku])->whereHas('order', fn ($q) => $q->where('order_number', $order))->exists()) {
                $errors[] = "Order {$order} already contains SKU {$sku}. This row is already recorded; existing quantities will not change. Use the authorized Amend Order Line action to request a change.";
            }
        } elseif ($sku !== '' && $order !== '') {
            $line = $this->receiptLineForRow($row);
            if (! $line) {
                $errors[] = "Order {$order}, SKU {$sku} must identify one open purchase order line. Check the order and SKU.";
            } else {
                try {
                    app(SupplierReceivingEligibility::class)->variantForLine($line);
                } catch (ValidationException $e) {
                    $errors = array_merge($errors, collect($e->errors())->flatten()->all());
                }
                if ($line->quantity_uncertain > 0) {
                    $errors[] = 'An earlier receipt needs reconciliation before more stock can be staged.';
                } elseif (ctype_digit((string) $quantity) && (int) $quantity > $line->quantity_available_to_receive) {
                    $errors[] = "Quantity exceeds the outstanding order quantity available to receive ({$line->quantity_available_to_receive}), after staged receipts.";
                }
            }
        }

        return $errors;
    }

    private function header(string $header): string
    {
        $header = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header)));

        return match (str_replace([' ', '-'], '_', $header)) {
            'order', 'order_number', 'orderid' => 'order_id',
            'quantity', 'qty', 'qty_ordered' => 'quantity_ordered',
            'qty_received', 'received' => 'quantity_received',
            'eta_date' => 'eta', default => str_replace([' ', '-'], '_', $header),
        };
    }

    private function skuMatchCount(string $sku, string $type): int
    {
        $variantQuery = Variant::query()->active()
            ->whereRaw('UPPER(TRIM(sku)) = ?', [$sku]);

        if ($type === 'order') {
            $variantQuery->whereHas('product', fn ($query) => $query->procurementOrderEligible());
        }

        $variants = $variantQuery->with('product:id,shopify_id,handle')->get();
        $draftCount = $type === 'order'
            ? NewProductDraft::query()
                ->whereIn(DB::raw('LOWER(TRIM(COALESCE(status, "")))'), ['active', 'draft'])
                ->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])
                ->get()
                ->reject(fn (NewProductDraft $draft): bool => $draft->isBundleOrStack() || $this->draftMirrorsVariant($draft, $variants))
                ->count()
            : 0;

        return $variants->count() + $draftCount;
    }

    private function draftMirrorsVariant(NewProductDraft $draft, $variants): bool
    {
        return $variants->contains(function (Variant $variant) use ($draft, $variants): bool {
            $product = $variant->product;

            return (filled($draft->shopify_id) && $draft->shopify_id === $product?->shopify_id)
                || (filled($draft->handle) && $draft->handle === $product?->handle)
                || ($draft->origin === NewProductDraft::ORIGIN_PRODUCT_MIRROR && $variants->count() === 1);
        });
    }
}
