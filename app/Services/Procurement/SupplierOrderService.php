<?php

namespace App\Services\Procurement;

use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierOrderService
{
    public function __construct(private readonly SupplierOrderProjectionService $projection) {}

    public function createForVariant(Variant $variant, string $orderNumber, mixed $quantity, mixed $eta, ?int $userId = null, string $source = 'cms'): ProcurementSupplierOrderLine
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') throw ValidationException::withMessages(['order_number' => 'Order ID is required.']);
        if (! is_numeric($quantity) || (int) $quantity <= 0 || (float) $quantity !== (float) (int) $quantity) {
            throw ValidationException::withMessages(['quantity_ordered' => 'Quantity must be a positive whole number.']);
        }
        try { $etaDate = $this->date((string) $eta)->toDateString(); }
        catch (\Throwable) { throw ValidationException::withMessages(['eta_date' => 'ETA must be a valid date.']); }

        $line = DB::transaction(function () use ($variant, $orderNumber, $quantity, $etaDate, $userId, $source): ProcurementSupplierOrderLine {
            $openCount = ProcurementSupplierOrderLine::query()->where('variant_id', $variant->id)->where('status', 'open')->lockForUpdate()->count();
            $order = ProcurementSupplierOrder::query()->firstOrCreate(['order_number' => $orderNumber], [
                'uuid' => (string) Str::uuid(), 'source' => $source, 'created_by' => $userId,
            ]);
            $existing = ProcurementSupplierOrderLine::query()->where('supplier_order_id', $order->id)->where('variant_id', $variant->id)->first();
            if ($existing) throw ValidationException::withMessages(['order_number' => 'This order already contains this SKU.']);
            if ($openCount >= 3) throw ValidationException::withMessages(['order_number' => 'This SKU already has three outstanding orders. Receive or complete one first.']);

            return ProcurementSupplierOrderLine::query()->create([
                'supplier_order_id' => $order->id, 'variant_id' => $variant->id,
                'sku' => strtoupper(trim((string) $variant->sku)), 'quantity_ordered' => (int) $quantity,
                'eta_date' => $etaDate, 'status' => 'open', 'source' => $source,
            ]);
        });
        $this->projection->projectVariant($variant->fresh(['procurementIncomingStock']));
        return $line;
    }

    public function createFromRow(array $row, ?int $userId = null, string $source = 'csv'): ProcurementSupplierOrderLine
    {
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        $matches = Variant::query()->active()->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])->get();
        if ($matches->count() !== 1) throw ValidationException::withMessages(['sku' => $matches->isEmpty() ? "SKU {$sku} was not found." : "SKU {$sku} is ambiguous."]);
        return $this->createForVariant($matches->first(), (string) ($row['order_id'] ?? ''), $row['quantity_ordered'] ?? null, $row['eta'] ?? null, $userId, $source);
    }

    private function date(string $value): \Illuminate\Support\Carbon
    {
        $value = trim($value);
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
            $date = \Illuminate\Support\Carbon::createFromFormat('!d/m/Y', $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) throw new \InvalidArgumentException;
            return $date;
        }
        return \Illuminate\Support\Carbon::parse($value);
    }
}
