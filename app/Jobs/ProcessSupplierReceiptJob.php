<?php

namespace App\Jobs;

use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\Procurement\SupplierOrderProjectionService;
use App\Services\ProductInventorySyncService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessSupplierReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public readonly int $receiptId) {}

    public function handle(ShopifyInventoryAdjustmentService $shopify, ProductInventorySyncService $inventory, SupplierOrderProjectionService $projection, ProcurementSheetSyncService $sheets): void
    {
        $receipt = ProcurementSupplierReceipt::query()->with('line.variant')->findOrFail($this->receiptId);
        if ($receipt->status !== 'succeeded') {
            if ($receipt->shopify_adjustment_started_at !== null) {
                $receipt->update(['status' => 'manual_review', 'error' => 'Shopify adjustment outcome is ambiguous; automatic retry was blocked to prevent double receiving.']);
                return;
            }
            $claimed = ProcurementSupplierReceipt::query()->whereKey($receipt->id)->whereNull('shopify_adjustment_started_at')
                ->update(['status' => 'processing', 'shopify_adjustment_started_at' => now(), 'error' => null]);
            if ($claimed !== 1) return;
            $receipt->refresh()->load('line.variant');
            try {
                $shopify->increaseAvailable($receipt->line->variant, $receipt->quantity_received, $receipt->shopify_reference_uri);
                $receipt->update(['status' => 'succeeded', 'shopify_adjusted_at' => now()]);
            } catch (\Throwable $e) {
                $receipt->update(['status' => 'manual_review', 'error' => $e->getMessage()]);
                throw $e;
            }
        }

        try {
            $line = $receipt->line()->with('variant')->firstOrFail();
            DB::transaction(function () use ($line): void {
                $locked = ProcurementSupplierOrderLine::query()->lockForUpdate()->findOrFail($line->id);
                $received = (int) $locked->receipts()->where('status', 'succeeded')->sum('quantity_received');
                if ($received >= (int) $locked->quantity_ordered) $locked->update(['status' => 'completed']);
            });
            $projection->projectVariant($line->variant->fresh(['procurementIncomingStock']));
            $result = $inventory->refreshVariants(collect([$line->variant]), $receipt->created_by);
            if (($result['failed'] ?? 0) > 0) throw new \RuntimeException(implode('; ', $result['failures'] ?? ['Shopify refresh failed.']));
            $sheets->publishOperational([$line->variant_id]);
            $receipt->update(['post_process_status' => 'completed', 'processed_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $receipt->update(['post_process_status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
