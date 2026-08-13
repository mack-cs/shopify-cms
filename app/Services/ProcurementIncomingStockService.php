<?php

namespace App\Services;

use App\Models\ProcurementIncomingStock;
use App\Models\ProcurementIncomingStockChange;
use App\Models\ProcurementPredictionInput;
use App\Models\ProcurementPredictionRun;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProcurementIncomingStockService
{
    public function normalizeQuantity(mixed $value, string $field = 'quantity'): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        if (! is_numeric($value) || (float) $value < 0 || floor((float) $value) !== (float) $value) {
            throw ValidationException::withMessages([
                $field => 'Order quantities must be non-negative whole numbers.',
            ]);
        }

        return (int) $value;
    }

    /**
     * @param  array{quantity_on_order_phase_1:mixed,quantity_on_order_phase_2:mixed,quantity_on_order_phase_3:mixed}  $phases
     */
    public function updateFromSheet(
        Variant $variant,
        array $phases,
        string $sourceSheet,
        ?int $sourceRow = null,
    ): ProcurementIncomingStock {
        $values = [
            'quantity_on_order_phase_1' => $this->normalizeQuantity(
                $phases['quantity_on_order_phase_1'] ?? null,
                'quantity_on_order_phase_1'
            ),
            'quantity_on_order_phase_2' => $this->normalizeQuantity(
                $phases['quantity_on_order_phase_2'] ?? null,
                'quantity_on_order_phase_2'
            ),
            'quantity_on_order_phase_3' => $this->normalizeQuantity(
                $phases['quantity_on_order_phase_3'] ?? null,
                'quantity_on_order_phase_3'
            ),
        ];
        $values['total_quantity_on_order'] = array_sum($values);
        $detectedAt = now();

        return DB::transaction(function () use ($variant, $values, $sourceSheet, $sourceRow, $detectedAt): ProcurementIncomingStock {
            $stock = ProcurementIncomingStock::query()
                ->where('variant_id', $variant->id)
                ->lockForUpdate()
                ->first();
            $stock ??= new ProcurementIncomingStock([
                'variant_id' => $variant->id,
                'quantity_on_order_phase_1' => 0,
                'quantity_on_order_phase_2' => 0,
                'quantity_on_order_phase_3' => 0,
                'total_quantity_on_order' => 0,
            ]);

            $previous = [
                (int) $stock->quantity_on_order_phase_1,
                (int) $stock->quantity_on_order_phase_2,
                (int) $stock->quantity_on_order_phase_3,
            ];
            $next = [
                $values['quantity_on_order_phase_1'],
                $values['quantity_on_order_phase_2'],
                $values['quantity_on_order_phase_3'],
            ];
            $changed = ! $stock->exists || $previous !== $next;

            $stock->forceFill(array_merge($values, [
                'sku' => strtoupper(trim((string) $variant->sku)),
                'source_sheet' => $sourceSheet,
                'detected_at' => $detectedAt,
                'input_changed_at' => $changed ? $detectedAt : $stock->input_changed_at,
            ]))->save();

            if ($changed) {
                ProcurementIncomingStockChange::query()->create([
                    'procurement_incoming_stock_id' => $stock->id,
                    'sku' => $stock->sku,
                    'source_sheet' => $sourceSheet,
                    'previous_phase_1' => $previous[0],
                    'previous_phase_2' => $previous[1],
                    'previous_phase_3' => $previous[2],
                    'new_phase_1' => $next[0],
                    'new_phase_2' => $next[1],
                    'new_phase_3' => $next[2],
                    'detected_at' => $detectedAt,
                    'metadata' => ['sheet_row' => $sourceRow],
                ]);
            }

            return $stock;
        });
    }

    public function snapshotForRun(ProcurementPredictionRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $lockedRun = ProcurementPredictionRun::query()->lockForUpdate()->findOrFail($run->id);
            $rows = [];
            $now = now();

            Variant::query()
                ->active()
                ->whereNotNull('sku')
                ->whereRaw("TRIM(COALESCE(sku, '')) != ''")
                ->whereHas('product', fn ($query) => $query->activeStatus())
                ->with(['product:id,shopify_id', 'procurementIncomingStock'])
                ->orderBy('id')
                ->chunkById(500, function ($variants) use (&$rows, $lockedRun, $now): void {
                    foreach ($variants as $variant) {
                        $stock = $variant->procurementIncomingStock;
                        $phase1 = (int) ($stock?->quantity_on_order_phase_1 ?? 0);
                        $phase2 = (int) ($stock?->quantity_on_order_phase_2 ?? 0);
                        $phase3 = (int) ($stock?->quantity_on_order_phase_3 ?? 0);
                        $rows[] = [
                            'procurement_prediction_run_id' => $lockedRun->id,
                            'variant_id' => $variant->id,
                            'shopify_product_id' => $variant->product?->shopify_id,
                            'shopify_variant_id' => $variant->shopify_id,
                            'sku' => strtoupper(trim((string) $variant->sku)),
                            'quantity_on_order_phase_1' => $phase1,
                            'quantity_on_order_phase_2' => $phase2,
                            'quantity_on_order_phase_3' => $phase3,
                            'total_quantity_on_order' => $phase1 + $phase2 + $phase3,
                            'source_sheet' => $stock?->source_sheet,
                            'source_changed_at' => $stock?->input_changed_at,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                });

            ProcurementPredictionInput::query()
                ->where('procurement_prediction_run_id', $lockedRun->id)
                ->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                ProcurementPredictionInput::query()->insert($chunk);
            }

            $hashRows = collect($rows)->map(fn (array $row): array => [
                $row['shopify_variant_id'], $row['sku'],
                $row['quantity_on_order_phase_1'], $row['quantity_on_order_phase_2'],
                $row['quantity_on_order_phase_3'], $row['total_quantity_on_order'],
            ])->all();
            $lockedRun->forceFill([
                'incoming_stock_snapshot_at' => $now,
                'incoming_stock_input_hash' => hash('sha256', json_encode($hashRows, JSON_THROW_ON_ERROR)),
            ])->save();
        });
    }

    public function markRunUsed(ProcurementPredictionRun $run): void
    {
        ProcurementIncomingStock::query()
            ->whereIn('variant_id', $run->incomingStockInputs()->select('variant_id'))
            ->update(['last_prediction_run_id' => $run->id]);
    }
}
