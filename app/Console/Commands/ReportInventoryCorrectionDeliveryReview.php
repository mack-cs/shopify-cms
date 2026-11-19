<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reports uncertain receipts and late/unreported rows; never sends or authorizes email. */
final class ReportInventoryCorrectionDeliveryReview extends Command
{
    protected $signature = 'inventory:correction-delivery-review-report {--output=}';

    protected $description = 'Read-only delivery review, including historical cutover uncertainty and corrections outside the weekly window';

    public function handle(): int
    {
        $path = $this->option('output') ?: storage_path('app/correction-delivery-review.csv');
        $file = fopen($path, 'wb');
        if (! $file) {
            $this->error('Could not write the delivery review report.');

            return self::FAILURE;
        }
        try {
            fputcsv($file, ['adjustment_id', 'occurred_at', 'app_name', 'delivery_status', 'week_start', 'report_status', 'error']);
            $rows = DB::table('shopify_inventory_adjustments as adjustments')
                ->leftJoin('direct_inventory_correction_report_items as items', 'items.adjustment_id', '=', 'adjustments.adjustment_id')
                ->leftJoin('direct_inventory_correction_reports as reports', 'reports.id', '=', 'items.report_id')
                ->whereRaw('lower(adjustments.reason) = ?', ['correction'])
                ->where(function ($q): void {
                    $q->whereNotIn('items.status', ['sent'])->orWhereNull('items.status');
                })
                ->select('adjustments.adjustment_id', 'adjustments.occurred_at', 'adjustments.app_name', 'items.status as item_status', 'reports.week_start', 'reports.status as report_status', 'reports.error')
                ->orderBy('adjustments.occurred_at')->cursor();
            foreach ($rows as $row) {
                fputcsv($file, [$row->adjustment_id, $row->occurred_at, $row->app_name, $row->item_status ?? 'not_in_delivery_ledger', $row->week_start, $row->report_status, $row->error]);
            }
        } finally {
            fclose($file);
        }
        $this->info('Wrote read-only delivery review. No receipt was assumed and no email was sent. This report does not authorize bulk retries.');

        return self::SUCCESS;
    }
}
