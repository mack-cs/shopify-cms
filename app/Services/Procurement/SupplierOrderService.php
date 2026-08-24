<?php

namespace App\Services\Procurement;

use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ChangeLog;
use App\Models\ProcurementIncomingStock;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierOrderService
{
    public function __construct(private readonly SupplierOrderSummaryService $summary) {}

    public function createForVariant(Variant $variant, string $orderNumber, mixed $quantity, mixed $eta, ?int $userId = null, string $source = 'cms', bool $allowExistingOrder = false): ProcurementSupplierOrderLine
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            throw ValidationException::withMessages(['order_number' => 'Order ID is required.']);
        }
        if (! is_numeric($quantity) || (int) $quantity <= 0 || (float) $quantity !== (float) (int) $quantity) {
            throw ValidationException::withMessages(['quantity_ordered' => 'Quantity must be a positive whole number.']);
        }
        try {
            $etaDate = $this->date((string) $eta)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['eta_date' => 'ETA must be a valid date.']);
        }

        $line = DB::transaction(function () use ($variant, $orderNumber, $quantity, $etaDate, $userId, $source, $allowExistingOrder): ProcurementSupplierOrderLine {
            $order = ProcurementSupplierOrder::query()->where('order_number', $orderNumber)->lockForUpdate()->first();
            if ($order && ! $allowExistingOrder) {
                throw ValidationException::withMessages(['order_number' => "Order ID {$orderNumber} already exists and cannot be uploaded as a new pending order."]);
            }
            $order ??= ProcurementSupplierOrder::query()->create([
                'uuid' => (string) Str::uuid(), 'order_number' => $orderNumber,
                'source' => $source, 'created_by' => $userId,
            ]);
            $existing = ProcurementSupplierOrderLine::query()->where('supplier_order_id', $order->id)->where('variant_id', $variant->id)->first();
            if ($existing) {
                throw ValidationException::withMessages(['order_number' => 'This order already contains this SKU.']);
            }
            $line = ProcurementSupplierOrderLine::query()->create([
                'supplier_order_id' => $order->id, 'variant_id' => $variant->id,
                'sku' => strtoupper(trim((string) $variant->sku)), 'quantity_ordered' => (int) $quantity,
                'eta_date' => $etaDate, 'status' => 'open', 'source' => $source,
            ]);
            $stock = $variant->procurementIncomingStock()->lockForUpdate()->first();
            if ($stock && (int) $stock->quantity_to_order > 0) {
                $previous = (int) $stock->quantity_to_order;
                $stock->update(['quantity_to_order' => 0]);
                ChangeLog::query()->create([
                    'import_id' => $variant->product?->import_id,
                    'product_id' => $variant->product_id,
                    'changed_by' => $userId,
                    'source' => $source.':order-created',
                    'model_type' => ProcurementIncomingStock::class,
                    'model_id' => $stock->id,
                    'field' => 'quantity_to_order',
                    'old_value' => (string) $previous,
                    'new_value' => '0',
                ]);
            }

            return $line;
        });
        $this->summary->refreshVariant($variant->fresh(['product', 'procurementIncomingStock']), $userId, $source);

        return $line;
    }

    public function createFromRow(array $row, ?int $userId = null, string $source = 'csv'): ProcurementSupplierOrderLine
    {
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        $matches = Variant::query()->active()->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['sku' => $matches->isEmpty() ? "SKU {$sku} was not found." : "SKU {$sku} is ambiguous."]);
        }

        return $this->createForVariant(
            $matches->first(), (string) ($row['order_id'] ?? ''),
            $row['quantity_ordered'] ?? null, $row['eta'] ?? null,
            $userId, $source, allowExistingOrder: true,
        );
    }

    private function date(string $value): Carbon
    {
        $value = trim($value);
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
            $date = Carbon::createFromFormat('!d/m/Y', $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                throw new \InvalidArgumentException;
            }

            return $date;
        }

        return Carbon::parse($value);
    }
}
