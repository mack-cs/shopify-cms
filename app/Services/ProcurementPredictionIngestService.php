<?php

namespace App\Services;

use App\Models\ProcurementPredictionRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProcurementPredictionIngestService
{
    /** @param array<string,mixed> $payload */
    public function persist(array $payload): ProcurementPredictionRun
    {
        $validated = Validator::make($payload, [
            'run_uuid' => ['required', 'uuid'],
            'run' => ['required', 'array'],
            'run.product_movement_generated_at' => ['nullable', 'date'],
            'run.product_movement_source_version' => ['nullable', 'string', 'max:64'],
            'run.model_version' => ['required', 'string', 'max:128'],
            'run.selected_model_information' => ['nullable', 'array'],
            'run.default_lead_time_days' => ['required', 'integer', 'min:1'],
            'run.attention_horizon_days' => ['required', 'integer', 'min:1'],
            'run.total_input_rows' => ['required', 'integer', 'min:0'],
            'run.total_excluded_rows' => ['required', 'integer', 'min:0'],
            'run.warning_count' => ['required', 'integer', 'min:0'],
            'run.error_count' => ['required', 'integer', 'min:0'],
            'run.metadata' => ['nullable', 'array'],
            'predictions' => ['required', 'array', 'max:10000'],
            'predictions.*.shopify_product_id' => ['nullable', 'string', 'max:128'],
            'predictions.*.shopify_variant_id' => ['required', 'string', 'max:128', 'distinct'],
            'predictions.*.sku' => ['required', 'string', 'max:255'],
            'predictions.*.product_name' => ['nullable', 'string', 'max:255'],
            'predictions.*.variant_name' => ['nullable', 'string', 'max:255'],
            'predictions.*.vendor' => ['nullable', 'string', 'max:255'],
            'predictions.*.main_collection' => ['nullable', 'string', 'max:255'],
            'predictions.*.product_type' => ['nullable', 'string', 'max:255'],
            'predictions.*.cms_movement_classification' => ['nullable', 'string', 'max:32'],
            'predictions.*.ml_movement_classification' => ['nullable', 'string', 'max:32'],
            'predictions.*.movement_classification_matches' => ['nullable', 'boolean'],
            'predictions.*.movement_score' => ['nullable', 'numeric'],
            'predictions.*.sales_consistency' => ['nullable', 'numeric'],
            'predictions.*.net_units_sold' => ['nullable', 'numeric'],
            'predictions.*.average_weekly_demand' => ['nullable', 'numeric'],
            'predictions.*.units_sold_per_30_in_stock_days' => ['nullable', 'numeric'],
            'predictions.*.ml_predicted_weekly_demand' => ['nullable', 'numeric'],
            'predictions.*.weighted_predicted_weekly_demand' => ['nullable', 'numeric'],
            'predictions.*.predicted_weekly_demand' => ['nullable', 'numeric'],
            'predictions.*.selected_prediction_method' => ['nullable', 'string', 'max:64'],
            'predictions.*.current_inventory' => ['nullable', 'numeric'],
            'predictions.*.ignore' => ['required', 'boolean'],
            'predictions.*.sale_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'predictions.*.total_quantity_on_order' => ['nullable', 'integer', 'min:0'],
            'predictions.*.procurement_actioned' => ['nullable', 'boolean'],
            'predictions.*.quantity_on_order' => ['nullable', 'integer', 'min:0'],
            'predictions.*.projected_inventory_position' => ['nullable', 'numeric'],
            'predictions.*.recommended_order_before_incoming_stock' => ['nullable', 'numeric'],
            'predictions.*.recommended_order_qty_before_incoming_stock' => ['nullable', 'numeric'],
            'predictions.*.additional_order_required' => ['nullable', 'numeric'],
            'predictions.*.incoming_stock_covers_requirement' => ['nullable', 'boolean'],
            'predictions.*.stockout_before_incoming_arrival' => ['nullable', 'boolean'],
            'predictions.*.in_stock_days' => ['nullable', 'numeric', 'min:0'],
            'predictions.*.out_of_stock_days' => ['nullable', 'numeric', 'min:0'],
            'predictions.*.attention_horizon_days' => ['required', 'integer', 'min:1'],
            'predictions.*.lead_time_days_used' => ['required', 'integer', 'min:1'],
            'predictions.*.lead_time_source' => ['required', 'string', 'max:64'],
            'predictions.*.estimated_days_of_stock_remaining' => ['nullable', 'numeric'],
            'predictions.*.predicted_runout_date' => ['nullable', 'date'],
            'predictions.*.stock_required_for_attention_horizon' => ['nullable', 'numeric'],
            'predictions.*.stock_required_for_lead_time' => ['nullable', 'numeric'],
            'predictions.*.preliminary_order_quantity' => ['nullable', 'numeric'],
            'predictions.*.currently_on_sale' => ['required', 'boolean'],
            'predictions.*.action_status' => ['required', 'string', 'max:40'],
            'predictions.*.action_reason' => ['nullable', 'string'],
            'predictions.*.data_quality_status' => ['nullable', 'string', 'max:32'],
            'predictions.*.data_quality_warning' => ['nullable', 'string'],
            'predictions.*.generated_at' => ['required', 'date'],
        ])->validate();

        Log::info('Procurement prediction persistence started', [
            'run_uuid' => $validated['run_uuid'],
            'prediction_rows' => count($validated['predictions']),
        ]);

        $run = DB::transaction(function () use ($validated): ProcurementPredictionRun {
            $run = ProcurementPredictionRun::query()
                ->where('run_uuid', $validated['run_uuid'])
                ->lockForUpdate()
                ->first();

            if (! $run instanceof ProcurementPredictionRun) {
                throw ValidationException::withMessages([
                    'run_uuid' => 'The CMS did not create this procurement run.',
                ]);
            }

            if ($run->status === ProcurementPredictionRun::STATUS_COMPLETED) {
                return $run;
            }

            $rows = collect($validated['predictions'])
                ->map(fn (array $row): array => $this->predictionRow($row, $run->id))
                ->all();

            $run->predictions()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('procurement_predictions')->insert($chunk);
            }

            $runData = (array) $validated['run'];
            $run->forceFill([
                'status' => ProcurementPredictionRun::STATUS_COMPLETED,
                'completed_at' => now(),
                'product_movement_generated_at' => $runData['product_movement_generated_at'] ?? null,
                'product_movement_source_version' => $runData['product_movement_source_version'] ?? null,
                'model_version' => $runData['model_version'],
                'selected_model_information' => $runData['selected_model_information'] ?? null,
                'default_lead_time_days' => (int) $runData['default_lead_time_days'],
                'attention_horizon_days' => (int) $runData['attention_horizon_days'],
                'total_input_rows' => (int) $runData['total_input_rows'],
                'total_excluded_rows' => (int) $runData['total_excluded_rows'],
                'total_prediction_rows' => count($rows),
                'warning_count' => (int) $runData['warning_count'],
                'error_count' => (int) $runData['error_count'],
                'error_message' => null,
                'metadata' => $runData['metadata'] ?? null,
            ])->save();

            return $run;
        });

        Log::info('Procurement prediction persistence completed', [
            'run_id' => $run->id,
            'prediction_rows' => $run->total_prediction_rows,
        ]);

        return $run->fresh() ?? $run;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function predictionRow(array $row, int $runId): array
    {
        $columns = [
            'shopify_product_id', 'shopify_variant_id', 'sku', 'product_name', 'variant_name',
            'vendor', 'main_collection', 'product_type', 'cms_movement_classification',
            'ml_movement_classification', 'movement_classification_matches', 'movement_score',
            'sales_consistency', 'net_units_sold', 'average_weekly_demand',
            'units_sold_per_30_in_stock_days', 'ml_predicted_weekly_demand',
            'weighted_predicted_weekly_demand', 'predicted_weekly_demand',
            'selected_prediction_method', 'current_inventory', 'ignore', 'sale_percentage',
            'in_stock_days', 'out_of_stock_days',
            'quantity_on_order_phase_1', 'order_id_phase_1', 'eta_date_phase_1',
            'confirmed_quantity_on_order_phase_1',
            'quantity_on_order_phase_2', 'order_id_phase_2', 'eta_date_phase_2',
            'confirmed_quantity_on_order_phase_2',
            'quantity_on_order_phase_3', 'order_id_phase_3', 'eta_date_phase_3',
            'confirmed_quantity_on_order_phase_3',
            'total_quantity_on_order', 'total_confirmed_quantity_on_order', 'procurement_actioned',
            'projected_inventory_position', 'recommended_order_before_incoming_stock',
            'additional_order_required', 'incoming_stock_covers_requirement',
            'stockout_before_incoming_arrival',
            'attention_horizon_days', 'lead_time_days_used', 'lead_time_source',
            'estimated_days_of_stock_remaining', 'predicted_runout_date',
            'stock_required_for_attention_horizon', 'stock_required_for_lead_time',
            'preliminary_order_quantity', 'currently_on_sale', 'action_status', 'action_reason',
            'data_quality_status', 'data_quality_warning', 'generated_at',
        ];

        $missing = array_values(array_diff([
            'shopify_variant_id', 'sku', 'ignore', 'attention_horizon_days', 'lead_time_days_used',
            'lead_time_source', 'action_status', 'generated_at',
        ], array_keys($row)));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'predictions' => 'Prediction row is missing: '.implode(', ', $missing),
            ]);
        }

        $result = array_intersect_key($row, array_flip($columns));
        $outstanding = (int) ($row['total_quantity_on_order'] ?? $row['quantity_on_order'] ?? 0);
        $result['total_quantity_on_order'] = $outstanding;
        foreach ([1, 2, 3] as $phase) {
            $result["quantity_on_order_phase_{$phase}"] = 0;
            $result["order_id_phase_{$phase}"] = null;
            $result["eta_date_phase_{$phase}"] = null;
            $result["confirmed_quantity_on_order_phase_{$phase}"] = 0;
        }
        $result['ignore'] = (bool) $row['ignore'];
        $result['total_confirmed_quantity_on_order'] = $outstanding;
        $result['procurement_actioned'] = $outstanding > 0;
        $result['recommended_order_before_incoming_stock'] = $row['recommended_order_before_incoming_stock']
            ?? $row['recommended_order_qty_before_incoming_stock'] ?? null;
        $result['additional_order_required'] = $row['additional_order_required']
            ?? $row['preliminary_order_quantity'] ?? null;
        $result['incoming_stock_covers_requirement'] = (bool) ($row['incoming_stock_covers_requirement'] ?? false);
        $result['stockout_before_incoming_arrival'] = (bool) ($row['stockout_before_incoming_arrival'] ?? false);
        if ($result['ignore']) {
            $result['recommended_order_before_incoming_stock'] = 0;
            $result['additional_order_required'] = 0;
            $result['preliminary_order_quantity'] = 0;
            $result['action_status'] = 'NO_ACTION';
            $result['action_reason'] = 'SKU is marked Ignore / end of life; sell through remaining inventory and do not replenish.';
        }
        $result['procurement_prediction_run_id'] = $runId;
        $result['created_at'] = now();
        $result['updated_at'] = now();

        return $result;
    }
}
