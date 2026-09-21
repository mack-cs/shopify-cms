<?php

namespace App\Http\Controllers;

use App\Models\ProcurementSupplierOrder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SupplierOrderReportExportController extends Controller
{
    public function __invoke(ProcurementSupplierOrder $order): StreamedResponse
    {
        $order->load(['createdBy', 'amendments.amendedBy', 'lines.variant.product', 'lines.draft', 'lines.receipts']);
        $filename = 'supplier-order-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $order->order_number ?: 'order-'.$order->id).'.csv';

        return response()->streamDownload(function () use ($order): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'Order ID',
                'Created By',
                'Last Amended By',
                'SKU',
                'Product',
                'Supplier',
                'Ordered',
                'Received',
                'Outstanding',
                'Status',
                'ETA',
                'GRVs',
            ]);

            $lastAmendment = $order->amendments->sortByDesc('created_at')->first();
            foreach ($order->lines->sortBy('sku') as $line) {
                fputcsv($handle, [
                    $order->order_number ?: 'Legacy order',
                    $order->createdBy?->name ?: $order->createdBy?->email ?: '',
                    $lastAmendment?->amendedBy?->name ?: $lastAmendment?->amendedBy?->email ?: '',
                    $line->sku,
                    $line->variant?->product?->title ?? $line->draft?->title ?? '',
                    $line->variant?->product?->vendor ?? '',
                    $line->quantity_ordered,
                    $line->quantity_received,
                    $line->quantity_outstanding,
                    ucfirst((string) $line->status),
                    $line->eta_date?->format('d/m/Y') ?? '',
                    $line->receipts->pluck('grv_number')->filter()->unique()->implode(', '),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
