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
        $writer->insertOne(['Item', 'SKU', 'Product', 'Vendor', 'Quantity Ordered', 'Order ID', 'ETA']);
        $item = 0;
        foreach ($this->variants($selected) as $variant) {
            $writer->insertOne([
                ++$item,
                $variant->sku,
                $variant->product?->title,
                $variant->product?->vendor,
                (int) ($variant->procurementIncomingStock?->quantity_to_order ?? 0) ?: '',
                '',
                '',
            ]);
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
                    $writer->insertOne([
                        $line->order?->order_number,
                        $variant->sku,
                        $line->quantity_outstanding,
                    ]);
                }
            }
        }

        return $writer->toString();
    }

    public function orderHistory(Collection $selected): string
    {
        $writer = Writer::createFromString();
        $writer->insertOne([
            'Order ID', 'SKU', 'Product', 'Vendor', 'Quantity Ordered', 'Quantity Received',
            'Quantity Outstanding', 'ETA', 'Status', 'Created Date', 'Completed Date',
        ]);
        foreach ($this->variants($selected) as $variant) {
            foreach ($variant->supplierOrderLines->sortByDesc('created_at') as $line) {
                $writer->insertOne([
                    $line->order?->order_number,
                    $variant->sku,
                    $variant->product?->title,
                    $variant->product?->vendor,
                    $line->quantity_ordered,
                    $line->quantity_received,
                    $line->quantity_outstanding,
                    $line->eta_date?->format('d/m/Y'),
                    $line->status,
                    $line->created_at?->format('d/m/Y H:i'),
                    $line->completed_at?->format('d/m/Y H:i'),
                ]);
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
            ->with(['product', 'procurementIncomingStock', 'supplierOrderLines.order', 'supplierOrderLines.receipts'])
            ->orderBy('sku')
            ->get();
    }
}
