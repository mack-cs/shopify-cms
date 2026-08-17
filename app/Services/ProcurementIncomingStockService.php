<?php

namespace App\Services;

use App\Models\ProcurementIncomingStock;
use App\Models\ProcurementIncomingStockChange;
use App\Models\ProcurementPredictionInput;
use App\Models\ProcurementPredictionRun;
use App\Models\Variant;
use Illuminate\Support\Carbon;
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

    public function normalizeBoolean(mixed $value, string $field = 'ignore'): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '' || in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        throw ValidationException::withMessages([$field => 'Ignore must be TRUE or FALSE.']);
    }

    public function normalizeOrderId(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_substr($normalized, 0, 255);
    }

    public function normalizeEtaDate(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value))->toDateString();
            }

            $normalized = trim((string) $value);
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $normalized)) {
                $date = Carbon::createFromFormat('!d/m/Y', $normalized);
                $errors = \DateTimeImmutable::getLastErrors();
                if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                    throw new \InvalidArgumentException('Invalid day/month/year date.');
                }

                return $date->toDateString();
            }

            return Carbon::parse($normalized)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'ETA must be a valid date.']);
        }
    }

    /**
     * @param array<string,mixed> $phases
     */
    public function updateFromSheet(
        Variant $variant,
        array $phases,
        string $sourceSheet,
        ?int $sourceRow = null,
    ): ProcurementIncomingStock {
        $values = ['ignore' => $this->normalizeBoolean($phases['ignore'] ?? false)];
        foreach ([1, 2, 3] as $phase) {
            $quantity = $this->normalizeQuantity(
                $phases["quantity_on_order_phase_{$phase}"] ?? null,
                "quantity_on_order_phase_{$phase}"
            );
            $orderId = $this->normalizeOrderId($phases["order_id_phase_{$phase}"] ?? null);
            $eta = $this->normalizeEtaDate(
                $phases["eta_date_phase_{$phase}"] ?? null,
                "eta_date_phase_{$phase}"
            );
            $values["quantity_on_order_phase_{$phase}"] = $quantity;
            $values["order_id_phase_{$phase}"] = $orderId;
            $values["eta_date_phase_{$phase}"] = $eta;
            $values["confirmed_quantity_on_order_phase_{$phase}"] =
                $quantity > 0 && $orderId !== null && $eta !== null ? $quantity : 0;
        }
        $values['total_quantity_on_order'] = array_sum(array_map(
            fn (int $phase): int => $values["quantity_on_order_phase_{$phase}"], [1, 2, 3]
        ));
        $values['total_confirmed_quantity_on_order'] = array_sum(array_map(
            fn (int $phase): int => $values["confirmed_quantity_on_order_phase_{$phase}"], [1, 2, 3]
        ));
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

            $workflowFields = array_merge(['ignore'], collect([1, 2, 3])->flatMap(fn (int $phase): array => [
                "quantity_on_order_phase_{$phase}", "order_id_phase_{$phase}", "eta_date_phase_{$phase}",
            ])->all());
            $previousWorkflow = collect($workflowFields)->mapWithKeys(
                fn (string $field): array => [$field => $stock->getAttribute($field)]
            )->all();
            $nextWorkflow = array_intersect_key($values, array_flip($workflowFields));
            $previous = [1, 2, 3];
            $previous = array_map(fn (int $phase): int => (int) $stock->getAttribute("quantity_on_order_phase_{$phase}"), $previous);
            $next = array_map(fn (int $phase): int => $values["quantity_on_order_phase_{$phase}"], [1, 2, 3]);
            $changed = ! $stock->exists || $this->workflowComparable($previousWorkflow) !== $this->workflowComparable($nextWorkflow);

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
                    'metadata' => [
                        'sheet_row' => $sourceRow,
                        'previous_workflow' => $previousWorkflow,
                        'new_workflow' => $nextWorkflow,
                        'confirmed_total' => $values['total_confirmed_quantity_on_order'],
                    ],
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
                        $confirmed1 = (int) ($stock?->confirmed_quantity_on_order_phase_1 ?? 0);
                        $confirmed2 = (int) ($stock?->confirmed_quantity_on_order_phase_2 ?? 0);
                        $confirmed3 = (int) ($stock?->confirmed_quantity_on_order_phase_3 ?? 0);
                        $rows[] = [
                            'procurement_prediction_run_id' => $lockedRun->id,
                            'variant_id' => $variant->id,
                            'shopify_product_id' => $variant->product?->shopify_id,
                            'shopify_variant_id' => $variant->shopify_id,
                            'sku' => strtoupper(trim((string) $variant->sku)),
                            'ignore' => (bool) ($stock?->ignore ?? false),
                            'quantity_on_order_phase_1' => $phase1,
                            'order_id_phase_1' => $stock?->order_id_phase_1,
                            'eta_date_phase_1' => $stock?->eta_date_phase_1?->toDateString(),
                            'confirmed_quantity_on_order_phase_1' => $confirmed1,
                            'quantity_on_order_phase_2' => $phase2,
                            'order_id_phase_2' => $stock?->order_id_phase_2,
                            'eta_date_phase_2' => $stock?->eta_date_phase_2?->toDateString(),
                            'confirmed_quantity_on_order_phase_2' => $confirmed2,
                            'quantity_on_order_phase_3' => $phase3,
                            'order_id_phase_3' => $stock?->order_id_phase_3,
                            'eta_date_phase_3' => $stock?->eta_date_phase_3?->toDateString(),
                            'confirmed_quantity_on_order_phase_3' => $confirmed3,
                            'total_quantity_on_order' => $phase1 + $phase2 + $phase3,
                            'total_confirmed_quantity_on_order' => $confirmed1 + $confirmed2 + $confirmed3,
                            'procurement_actioned' => ($confirmed1 + $confirmed2 + $confirmed3) > 0,
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
                $row['shopify_variant_id'], $row['sku'], $row['ignore'],
                $row['quantity_on_order_phase_1'], $row['order_id_phase_1'], $row['eta_date_phase_1'],
                $row['quantity_on_order_phase_2'], $row['order_id_phase_2'], $row['eta_date_phase_2'],
                $row['quantity_on_order_phase_3'], $row['order_id_phase_3'], $row['eta_date_phase_3'],
                $row['total_quantity_on_order'], $row['total_confirmed_quantity_on_order'],
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

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function workflowComparable(array $values): array
    {
        foreach ([1, 2, 3] as $phase) {
            $value = $values["eta_date_phase_{$phase}"] ?? null;
            $values["eta_date_phase_{$phase}"] = $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : ($value === null ? null : substr((string) $value, 0, 10));
            $values["quantity_on_order_phase_{$phase}"] = (int) ($values["quantity_on_order_phase_{$phase}"] ?? 0);
            $values["order_id_phase_{$phase}"] = $this->normalizeOrderId($values["order_id_phase_{$phase}"] ?? null);
        }
        $values['ignore'] = (bool) ($values['ignore'] ?? false);
        ksort($values);

        return $values;
    }
}
