<?php

namespace App\Services;

use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductMovementMlExportService
{
    public const SOURCE_VERSION = 'product-movement-v2';

    public function download(?int $runId = null): StreamedResponse
    {
        $run = $this->completedRun($runId);
        $summary = $this->eligibilitySummary($run);
        $run->forceFill([
            'settings' => array_merge((array) $run->settings, ['ml_export' => $summary]),
        ])->save();

        Log::info('Product Movement ML input prepared', array_merge([
            'run_id' => $run->id,
        ], $summary));

        return response()->streamDownload(function () use ($run): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open the Product Movement ML CSV stream.');
            }

            fputcsv($handle, $this->headers());
            $this->eligibleQuery($run)
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($handle, $run): void {
                    foreach ($rows as $row) {
                        if (!$row instanceof ProductMovementReportRow) {
                            continue;
                        }
                        fputcsv($handle, $this->row($row, $run));
                    }
                });
            fclose($handle);
        }, "product_movement_ml_run_{$run->id}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
            'X-Product-Movement-Run-Id' => (string) $run->id,
            'X-Product-Movement-Generated-At' => $run->completed_at?->toIso8601String() ?? '',
            'X-Product-Movement-Source-Version' => $run->source_version ?: self::SOURCE_VERSION,
        ]);
    }

    /** @return array<int,string> */
    public function headers(): array
    {
        return [
            'shopify_product_id', 'shopify_variant_id', 'sku', 'product_title',
            'variant_title', 'vendor', 'product_type', 'product_status',
            'analysis_start_date', 'analysis_end_date', 'analysis_days', 'months_analysed',
            'gross_units_sold', 'refund_units', 'net_units_sold', 'order_count',
            'average_units_per_30_days', 'average_weekly_demand', 'months_with_sales',
            'sales_consistency', 'days_since_last_sale', 'last_sale_date',
            'first_inventory_snapshot_date', 'inventory_snapshot_days', 'in_stock_days',
            'out_of_stock_days', 'units_sold_per_30_in_stock_days',
            'current_inventory', 'opening_inventory', 'average_inventory', 'closing_inventory',
            'movement_score', 'movement_classification', 'movement_reason',
            'currently_on_sale', 'price', 'compare_at_price',
            'data_quality_status', 'data_quality_note', 'generated_at', 'source_version',
            'direct_net_units_sold', 'stack_attributed_net_units', 'movement_product_kind',
        ];
    }

    private function completedRun(?int $runId): ProductMovementReportRun
    {
        $run = ProductMovementReportRun::query()
            ->where('status', ProductMovementReportRun::STATUS_COMPLETED)
            ->whereNotNull('calculation_date')
            ->when($runId !== null, fn (Builder $query): Builder => $query->whereKey($runId))
            ->latest('calculation_date')
            ->latest('id')
            ->first();

        if (!$run instanceof ProductMovementReportRun) {
            throw new \RuntimeException('No successfully completed daily Product Movement snapshot is available.');
        }

        return $run;
    }

    private function eligibleQuery(ProductMovementReportRun $run): Builder
    {
        return ProductMovementReportRow::query()
            ->where('product_movement_report_run_id', $run->id)
            ->whereRaw("LOWER(COALESCE(product_status, '')) = ?", ['active'])
            ->whereNotNull('shopify_product_id')->where('shopify_product_id', '!=', '')
            ->whereNotNull('shopify_variant_id')->where('shopify_variant_id', '!=', '')
            ->whereNotNull('sku')->whereRaw('TRIM(sku) NOT IN (?, ?)', ['', '0']);
    }

    /** @return array<string,mixed> */
    private function eligibilitySummary(ProductMovementReportRun $run): array
    {
        $reasons = [];
        $eligible = 0;

        $run->rows()->select([
            'id', 'product_status', 'inventory_tracked', 'shopify_product_id',
            'shopify_variant_id', 'sku', 'movement_product_kind',
        ])->orderBy('id')->chunkById(1000, function ($rows) use (&$eligible, &$reasons): void {
            foreach ($rows as $row) {
                $reason = $this->exclusionReason($row);
                if ($reason === null) {
                    $eligible++;
                    continue;
                }
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        });

        return [
            'total_rows' => (int) $run->row_count,
            'eligible_rows' => $eligible,
            'excluded_rows' => array_sum($reasons),
            'exclusion_reasons' => $reasons,
        ];
    }

    private function exclusionReason(ProductMovementReportRow $row): ?string
    {
        if (strtolower(trim((string) $row->product_status)) !== 'active') {
            return 'product_not_active';
        }
        if (trim((string) $row->shopify_product_id) === '') {
            return 'missing_shopify_product_id';
        }
        if (trim((string) $row->shopify_variant_id) === '') {
            return 'missing_shopify_variant_id';
        }
        if (in_array(strtoupper(trim((string) $row->sku)), ['', '0', 'NAN', 'NONE'], true)) {
            return 'invalid_sku';
        }
        return null;
    }

    /** @return array<int,mixed> */
    private function row(ProductMovementReportRow $row, ProductMovementReportRun $run): array
    {
        $analysisDays = $row->analysis_start_date->diffInDays($row->analysis_end_date) + 1;
        $qualityNote = trim((string) $row->data_quality_note);

        return [
            $row->shopify_product_id,
            $row->shopify_variant_id,
            strtoupper(trim((string) $row->sku)),
            $row->product_title,
            $row->variant_title,
            $row->vendor,
            $row->product_type,
            $row->product_status,
            $row->analysis_start_date->toDateString(),
            $row->analysis_end_date->toDateString(),
            $analysisDays,
            $row->months_analysed,
            $row->gross_units_sold,
            $row->refunded_units,
            $row->net_units_sold,
            $row->order_count,
            $row->average_units_per_30_days,
            round(((float) $row->average_units_per_30_days / 30) * 7, 4),
            $row->months_with_sales,
            $row->sales_consistency_percentage,
            $row->days_since_last_sale,
            $row->last_sale_date?->toDateString(),
            $row->first_inventory_snapshot_date?->toDateString(),
            $row->snapshot_days_available,
            $row->in_stock_days,
            $row->out_of_stock_days,
            $row->units_sold_per_30_in_stock_days,
            $row->current_inventory,
            $row->opening_snapshot_inventory,
            $row->average_snapshot_inventory,
            $row->closing_snapshot_inventory,
            $row->movement_score,
            $this->standardClassification((string) $row->movement_classification),
            $row->manager_reason,
            $row->currently_on_sale ? 'true' : 'false',
            $row->current_price,
            $row->compare_at_price,
            $qualityNote === '' ? 'OK' : 'WARNING',
            $qualityNote,
            $run->completed_at?->toIso8601String(),
            $run->source_version ?: self::SOURCE_VERSION,
            $row->direct_net_units_sold,
            $row->stack_attributed_net_units,
            $row->movement_product_kind,
        ];
    }

    private function standardClassification(string $classification): string
    {
        return match (strtolower(trim($classification))) {
            'fast_moving' => 'FAST_MOVING',
            'medium_moving' => 'MEDIUM_MOVING',
            'slow_moving', 'out_of_stock_or_unavailable' => 'SLOW_MOVING',
            'new_product' => 'NEW_PRODUCT',
            default => 'NO_SALES',
        };
    }
}
