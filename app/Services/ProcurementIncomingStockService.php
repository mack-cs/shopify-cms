<?php

namespace App\Services;

use App\Models\ChangeLog;
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

    /** @param array<string,mixed> $inputs */
    public function updateFromSheet(
        Variant $variant,
        array $inputs,
        string $sourceSheet,
        ?int $sourceRow = null,
        ?int $changedBy = null,
    ): ProcurementIncomingStock {
        $values = [
            'ignore' => $this->normalizeBoolean($inputs['ignore'] ?? false),
            'quantity_to_order' => $this->normalizeQuantity($inputs['quantity_to_order'] ?? null, 'quantity_to_order'),
        ];
        $detectedAt = now();

        return DB::transaction(function () use ($variant, $values, $sourceSheet, $sourceRow, $changedBy, $detectedAt): ProcurementIncomingStock {
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
                'total_confirmed_quantity_on_order' => 0,
                'number_of_wip_orders' => 0,
            ]);

            $workflowFields = ['ignore', 'quantity_to_order'];
            $previousWorkflow = collect($workflowFields)->mapWithKeys(
                fn (string $field): array => [$field => $stock->getAttribute($field)]
            )->all();
            $nextWorkflow = array_intersect_key($values, array_flip($workflowFields));
            $changed = ! $stock->exists || $this->humanInputComparable($previousWorkflow) !== $this->humanInputComparable($nextWorkflow);
            $predictionChanged = ! $stock->exists
                || (bool) ($previousWorkflow['ignore'] ?? false) !== (bool) $nextWorkflow['ignore'];

            $stock->forceFill(array_merge($values, [
                'sku' => strtoupper(trim((string) $variant->sku)),
                'source_sheet' => $sourceSheet,
                'detected_at' => $detectedAt,
                'input_changed_at' => $predictionChanged ? $detectedAt : $stock->input_changed_at,
            ]))->save();

            if ($changed) {
                ProcurementIncomingStockChange::query()->create([
                    'procurement_incoming_stock_id' => $stock->id,
                    'sku' => $stock->sku,
                    'source_sheet' => $sourceSheet,
                    'previous_phase_1' => 0,
                    'previous_phase_2' => 0,
                    'previous_phase_3' => 0,
                    'new_phase_1' => 0,
                    'new_phase_2' => 0,
                    'new_phase_3' => 0,
                    'detected_at' => $detectedAt,
                    'metadata' => [
                        'sheet_row' => $sourceRow,
                        'previous_workflow' => $previousWorkflow,
                        'new_workflow' => $nextWorkflow,
                        'order_summary_is_cms_owned' => true,
                    ],
                ]);

                foreach ($workflowFields as $field) {
                    $oldValue = $this->comparableAuditValue($previousWorkflow[$field] ?? null);
                    $newValue = $this->comparableAuditValue($nextWorkflow[$field] ?? null);
                    if ($oldValue === $newValue) {
                        continue;
                    }
                    ChangeLog::query()->create([
                        'import_id' => $variant->product?->import_id,
                        'product_id' => $variant->product_id,
                        'changed_by' => $changedBy,
                        'source' => $sourceSheet,
                        'model_type' => ProcurementIncomingStock::class,
                        'model_id' => $stock->id,
                        'field' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                    ]);
                }
            }

            return $stock;
        });
    }

    private function comparableAuditValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return (string) $value;
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
                ->whereHas('product', fn ($query) => $query->activeStatus()->nonBundle())
                ->with(['product:id,shopify_id', 'procurementIncomingStock'])
                ->orderBy('id')
                ->chunkById(500, function ($variants) use (&$rows, $lockedRun, $now): void {
                    foreach ($variants as $variant) {
                        $stock = $variant->procurementIncomingStock;
                        $total = (int) ($stock?->total_quantity_on_order ?? 0);
                        $rows[] = [
                            'procurement_prediction_run_id' => $lockedRun->id,
                            'variant_id' => $variant->id,
                            'shopify_product_id' => $variant->product?->shopify_id,
                            'shopify_variant_id' => $variant->shopify_id,
                            'sku' => strtoupper(trim((string) $variant->sku)),
                            'ignore' => (bool) ($stock?->ignore ?? false),
                            // Legacy phase fields remain zero during the transition. The
                            // canonical incoming quantity is derived from CMS order lines.
                            'quantity_on_order_phase_1' => 0,
                            'order_id_phase_1' => null,
                            'eta_date_phase_1' => null,
                            'confirmed_quantity_on_order_phase_1' => 0,
                            'quantity_on_order_phase_2' => 0,
                            'order_id_phase_2' => null,
                            'eta_date_phase_2' => null,
                            'confirmed_quantity_on_order_phase_2' => 0,
                            'quantity_on_order_phase_3' => 0,
                            'order_id_phase_3' => null,
                            'eta_date_phase_3' => null,
                            'confirmed_quantity_on_order_phase_3' => 0,
                            'total_quantity_on_order' => $total,
                            'total_confirmed_quantity_on_order' => $total,
                            'procurement_actioned' => $total > 0,
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
                $row['total_quantity_on_order'],
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
    private function humanInputComparable(array $values): array
    {
        $values['ignore'] = (bool) ($values['ignore'] ?? false);
        $values['quantity_to_order'] = (int) ($values['quantity_to_order'] ?? 0);
        ksort($values);

        return $values;
    }
}
