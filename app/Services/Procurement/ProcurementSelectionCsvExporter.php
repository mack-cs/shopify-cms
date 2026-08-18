<?php

namespace App\Services\Procurement;

use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use League\Csv\Writer;

final class ProcurementSelectionCsvExporter
{
    public function pendingOrders(Collection $selected): string
    {
        $writer = Writer::createFromString();
        $writer->insertOne(['SKU', 'Order ID', 'Quantity Ordered', 'ETA']);
        foreach ($this->variants($selected) as $variant) {
            $writer->insertOne([$variant->sku, '', '', '']);
        }

        return $writer->toString();
    }

    public function receipts(Collection $selected): string
    {
        $writer = Writer::createFromString();
        $writer->insertOne(['Order ID', 'SKU', 'Quantity Received']);
        foreach ($this->variants($selected) as $variant) {
            foreach ($variant->supplierOrderLines->where('status', 'open') as $line) {
                if ($line->quantity_outstanding > 0) {
                    $writer->insertOne([$line->order?->order_number, $variant->sku, '']);
                }
            }
        }

        return $writer->toString();
    }

    private function variants(Collection $selected): Collection
    {
        return Variant::query()
            ->active()
            ->whereKey($selected->pluck('id'))
            ->whereNotNull('sku')
            ->whereRaw("TRIM(COALESCE(sku, '')) != ''")
            ->whereHas('product', fn (Builder $query): Builder => $query
                ->whereRaw('LOWER(COALESCE(status, "")) NOT IN (?, ?)', ['archived', 'unlisted']))
            ->with(['supplierOrderLines.order', 'supplierOrderLines.receipts'])
            ->orderBy('sku')
            ->get();
    }
}
