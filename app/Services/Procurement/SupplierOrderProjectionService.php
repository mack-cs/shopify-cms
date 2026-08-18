<?php

namespace App\Services\Procurement;

use App\Models\ProcurementSupplierOrderLine;
use App\Models\Variant;
use App\Services\ProcurementIncomingStockService;

final class SupplierOrderProjectionService
{
    public function __construct(private readonly ProcurementIncomingStockService $incomingStock) {}

    public function projectVariant(Variant $variant, ?int $changedBy = null, string $source = 'cms:supplier-orders'): void
    {
        $lines = ProcurementSupplierOrderLine::query()
            ->where('variant_id', $variant->id)->where('status', 'open')
            ->with('order')->withSum(['receipts as received_quantity' => fn ($q) => $q->where('status', 'succeeded')], 'quantity_received')
            ->orderByRaw('eta_date IS NULL')->orderBy('eta_date')->orderBy('id')->get()
            ->filter(fn (ProcurementSupplierOrderLine $line): bool => (int) $line->quantity_ordered > (int) ($line->received_quantity ?? 0))
            ->values();

        if ($lines->count() > 3) {
            throw new \DomainException('A SKU can have at most three outstanding supplier orders because the procurement Sheet has three phases.');
        }

        $workflow = ['ignore' => (bool) ($variant->procurementIncomingStock?->ignore ?? false)];
        foreach ([1, 2, 3] as $phase) {
            $line = $lines->get($phase - 1);
            $workflow["quantity_on_order_phase_{$phase}"] = $line
                ? (int) $line->quantity_ordered - (int) ($line->received_quantity ?? 0) : 0;
            $workflow["order_id_phase_{$phase}"] = $line?->order?->order_number;
            $workflow["eta_date_phase_{$phase}"] = $line?->eta_date?->toDateString();
        }

        $this->incomingStock->updateFromSheet($variant, $workflow, $source, null, $changedBy);
    }
}
