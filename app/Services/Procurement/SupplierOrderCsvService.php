<?php

namespace App\Services\Procurement;

use App\Jobs\ProcessSupplierReceiptJob;
use App\Models\ProcurementSupplierImportBatch;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Variant;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierOrderCsvService
{
    public function __construct(private readonly SupplierOrderService $orders, private readonly SupplierReceiptService $receipts, private readonly ProcurementSheetSyncService $sheets) {}

    public function preview(string $path, string $type, ?int $userId = null, ?string $filename = null): ProcurementSupplierImportBatch
    {
        if (! in_array($type, ['order', 'receipt'], true)) {
            throw new \InvalidArgumentException('Unsupported supplier CSV type.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('The CSV could not be read.');
        }
        $hash = hash('sha256', $contents);
        $existing = ProcurementSupplierImportBatch::query()->where('type', $type)->where('file_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);
        $headers = array_map([$this, 'header'], fgetcsv($handle) ?: []);
        $required = $type === 'order' ? ['sku', 'order_id', 'quantity_ordered', 'eta'] : ['order_id', 'sku', 'quantity_received'];
        $missing = array_values(array_diff($required, $headers));
        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => 'Missing CSV header(s): '.implode(', ', $missing).'.']);
        }
        $rows = [];
        $errors = [];
        $number = 1;
        $seenOrderLines = [];
        while (($values = fgetcsv($handle)) !== false) {
            $number++;
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }
            $rowErrors = $this->validateRow($row, $type);
            if ($type === 'order') {
                $lineKey = mb_strtoupper(trim((string) ($row['order_id'] ?? ''))).'|'.mb_strtoupper(trim((string) ($row['sku'] ?? '')));
                if (isset($seenOrderLines[$lineKey])) {
                    $rowErrors[] = 'Order ID and SKU are duplicated within this CSV';
                }
                $seenOrderLines[$lineKey] = true;
            }
            $row['_row'] = $number;
            $row['_valid'] = $rowErrors === [];
            $rows[] = $row;
            if ($rowErrors !== []) {
                $errors[(string) $number] = $rowErrors;
            }
        }
        fclose($handle);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The CSV contains no data rows.']);
        }

        return ProcurementSupplierImportBatch::query()->create([
            'uuid' => (string) Str::uuid(), 'type' => $type, 'original_filename' => $filename,
            'file_hash' => $hash, 'status' => 'previewed', 'preview_rows' => $rows, 'errors' => $errors ?: null,
            'valid_count' => collect($rows)->where('_valid', true)->count(), 'invalid_count' => count($errors), 'created_by' => $userId,
        ]);
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
        $contents = trim(str_replace("\r\n", "\n", $contents));
        if ($contents === '') {
            $label = $type === 'order' ? 'purchase-order' : 'received-order';
            throw ValidationException::withMessages(['pasted_rows' => "Paste at least one {$label} row."]);
        }
        $lines = array_values(array_filter(explode("\n", $contents), fn (string $line): bool => trim($line) !== ''));
        $first = array_map([$this, 'header'], str_getcsv($lines[0], "\t"));
        $required = $type === 'order'
            ? ['sku', 'order_id', 'quantity_ordered', 'eta']
            : ['order_id', 'sku', 'quantity_received'];
        $hasHeader = array_diff($required, $first) === [];
        $columnCount = count(str_getcsv($lines[0], "\t"));
        $headers = $hasHeader
            ? $first
            : ($type === 'order'
                ? match ($columnCount) {
                    4 => ['sku', 'quantity_ordered', 'order_id', 'eta'],
                    5 => ['item', 'sku', 'quantity_ordered', 'order_id', 'eta'],
                    default => ['item', 'sku', 'product', 'vendor', 'quantity_ordered', 'order_id', 'eta'],
                }
                : ['order_id', 'sku', 'quantity_received']);
        $dataLines = $hasHeader ? array_slice($lines, 1) : $lines;
        if ($dataLines === []) {
            $label = $type === 'order' ? 'purchase order' : 'received order';
            throw ValidationException::withMessages(['pasted_rows' => "The pasted {$label} has no data rows."]);
        }

        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($dataLines as $offset => $line) {
            $values = str_getcsv($line, "\t");
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }
            $rowErrors = $this->validateRow($row, $type);
            $key = mb_strtoupper((string) ($row['order_id'] ?? '')).'|'.mb_strtoupper((string) ($row['sku'] ?? ''));
            if (isset($seen[$key])) {
                $rowErrors[] = $type === 'order'
                    ? 'Order ID and SKU are duplicated within the pasted rows'
                    : 'Order ID and SKU have more than one received row; combine them into one quantity';
            }
            $seen[$key] = true;
            $rowNumber = $offset + ($hasHeader ? 2 : 1);
            $row['_row'] = $rowNumber;
            $row['_valid'] = $rowErrors === [];
            $rows[] = $row;
            if ($rowErrors !== []) {
                $errors[(string) $rowNumber] = $rowErrors;
            }
        }

        $hash = hash('sha256', $contents);
        $existing = ProcurementSupplierImportBatch::query()->where('type', $type)->where('file_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }

        return ProcurementSupplierImportBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'original_filename' => $type === 'order' ? 'pasted-purchase-order.tsv' : 'pasted-received-orders.tsv',
            'file_hash' => $hash,
            'status' => 'previewed',
            'preview_rows' => $rows,
            'errors' => $errors ?: null,
            'valid_count' => collect($rows)->where('_valid', true)->count(),
            'invalid_count' => count($errors),
            'created_by' => $userId,
        ]);
    }

    public function confirm(string $uuid, ?int $userId = null, bool $dispatchReceipts = true): ProcurementSupplierImportBatch
    {
        $batch = ProcurementSupplierImportBatch::query()->where('uuid', $uuid)->firstOrFail();
        if ($batch->status === 'completed') {
            if ($batch->type === 'order') {
                $this->publishOrderRows($batch);
            }

            return $batch;
        }
        if ($batch->invalid_count > 0) {
            throw ValidationException::withMessages(['batch_uuid' => 'Fix the invalid preview rows before confirming this import.']);
        }
        $receiptIds = [];
        DB::transaction(function () use ($batch, $userId, &$receiptIds): void {
            $locked = ProcurementSupplierImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->status === 'completed') {
                return;
            }
            if ($locked->type === 'order') {
                $orderIds = collect($locked->preview_rows)->pluck('order_id')->map(fn ($id) => trim((string) $id))->filter()->unique();
                $existingIds = ProcurementSupplierOrder::query()->whereIn('order_number', $orderIds)->lockForUpdate()->pluck('order_number');
                if ($existingIds->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'batch_uuid' => 'Order ID(s) already exist and this pending-order import was rejected: '.$existingIds->implode(', ').'.',
                    ]);
                }
            }
            $locked->update(['status' => 'processing', 'confirmed_at' => now()]);
            foreach ($locked->preview_rows as $index => $row) {
                if ($locked->type === 'order') {
                    $this->orders->createFromRow($row, $userId, 'csv');
                } else {
                    $receipt = $this->receipts->createFromRow($row, "csv:{$locked->uuid}:".($index + 1), $userId, $locked->id, false);
                    $receiptIds[] = $receipt->id;
                }
            }
            $locked->update(['status' => 'completed', 'completed_at' => now()]);
        });
        if ($dispatchReceipts) {
            foreach (array_unique($receiptIds) as $id) {
                ProcessSupplierReceiptJob::dispatch($id)->onQueue('procurement');
            }
        }
        if ($batch->type === 'order') {
            $this->publishOrderRows($batch);
        }

        return $batch->fresh();
    }

    private function publishOrderRows(ProcurementSupplierImportBatch $batch): void
    {
        $skus = collect($batch->preview_rows)->pluck('sku')->map(fn ($sku) => strtoupper(trim((string) $sku)))->unique();
        $ids = Variant::query()->active()->whereIn(DB::raw('UPPER(TRIM(sku))'), $skus)->pluck('id')->all();
        $this->sheets->publishOperational($ids, includeHumanInputs: true);
    }

    private function validateRow(array $row, string $type): array
    {
        $errors = [];
        foreach ($type === 'order' ? ['sku', 'order_id', 'quantity_ordered', 'eta'] : ['sku', 'order_id', 'quantity_received'] as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $errors[] = "{$field} is required";
            }
        }
        $quantity = $row[$type === 'order' ? 'quantity_ordered' : 'quantity_received'] ?? null;
        if (! ctype_digit((string) $quantity) || (int) $quantity <= 0) {
            $errors[] = 'quantity must be a positive whole number';
        }
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        if ($sku !== '' && Variant::query()->active()->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])->count() !== 1) {
            $errors[] = 'SKU must match exactly one active variant';
        }
        if ($type === 'order' && trim((string) ($row['eta'] ?? '')) !== '') {
            try {
                $eta = trim((string) $row['eta']);
                preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $eta)
                    ? Carbon::createFromFormat('!d/m/Y', $eta)
                    : Carbon::parse($eta);
                $dateErrors = \DateTimeImmutable::getLastErrors();
                if (is_array($dateErrors) && (($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0)) {
                    throw new \InvalidArgumentException;
                }
            } catch (\Throwable) {
                $errors[] = 'eta is not a valid date';
            }
        }
        if ($type === 'order' && trim((string) ($row['order_id'] ?? '')) !== ''
            && ProcurementSupplierOrder::query()->where('order_number', trim((string) $row['order_id']))->exists()) {
            $errors[] = 'Order ID already exists; use the receipt template to fulfil an existing order';
        }
        if ($type === 'receipt' && $sku !== '' && trim((string) ($row['order_id'] ?? '')) !== '') {
            $lines = ProcurementSupplierOrderLine::query()->where('status', 'open')
                ->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])
                ->whereHas('order', fn ($query) => $query->where('order_number', trim((string) $row['order_id'])))->get();
            if ($lines->count() !== 1) {
                $errors[] = 'Order ID and SKU must match exactly one open order line';
            } elseif (ctype_digit((string) $quantity)) {
                $reserved = (int) $lines->first()->receipts()->whereIn('status', ['pending', 'processing', 'succeeded'])->sum('quantity_received');
                if ((int) $quantity > (int) $lines->first()->quantity_ordered - $reserved) {
                    $errors[] = 'quantity exceeds the outstanding order quantity';
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
}
