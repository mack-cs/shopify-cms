<?php

namespace App\Services;

use App\Jobs\RunProcurementPipelineJob;
use App\Models\ProcurementPredictionRun;
use App\Models\ProductMovementReportRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class ProcurementPipelineService
{
    public function __construct(
        private readonly ProductMovementReportService $movementReports,
    ) {
    }

    /** @return array{run:ProcurementPredictionRun,queued:bool} */
    public function queue(string $calculationDate, ?int $userId = null, bool $forceRecalculation = false): array
    {
        $leadTime = (int) config('procurement.default_lead_time_days', 56);
        $attentionHorizon = (int) config('procurement.attention_horizon_days', 21);
        $months = (int) config('procurement.movement_months', 6);
        if ($leadTime < 1 || $attentionHorizon < 1) {
            throw new \RuntimeException('Procurement lead time and attention horizon must be positive integers.');
        }

        $timezone = (string) config('procurement.timezone', 'Africa/Johannesburg');
        $date = Carbon::parse($calculationDate, $timezone)->toDateString();
        $movementRun = $this->movementReports->createDailyRun($date, $months, $userId);

        $calculationDateValue = Carbon::parse($date, $timezone)->startOfDay();
        $run = ProcurementPredictionRun::query()->firstOrCreate(
            ['calculation_date' => $calculationDateValue],
            [
                'run_uuid' => (string) Str::uuid(),
                'status' => ProcurementPredictionRun::STATUS_QUEUED,
                'product_movement_report_run_id' => $movementRun->id,
                'requested_by' => $userId,
                'default_lead_time_days' => $leadTime,
                'attention_horizon_days' => $attentionHorizon,
            ],
        );

        if (! $run->wasRecentlyCreated && in_array($run->status, [
            ProcurementPredictionRun::STATUS_QUEUED,
            ProcurementPredictionRun::STATUS_RUNNING,
        ], true)) {
            return ['run' => $run, 'queued' => false];
        }

        if (! $run->wasRecentlyCreated
            && $run->status === ProcurementPredictionRun::STATUS_COMPLETED
            && ! $forceRecalculation) {
            return ['run' => $run, 'queued' => false];
        }

        if (in_array($run->status, [
            ProcurementPredictionRun::STATUS_FAILED,
            ProcurementPredictionRun::STATUS_COMPLETED,
        ], true) && ! $run->wasRecentlyCreated) {
            $run->forceFill([
                'status' => ProcurementPredictionRun::STATUS_QUEUED,
                'requested_by' => $userId,
                'started_at' => null,
                'completed_at' => null,
                'error_message' => null,
                'error_count' => 0,
                'product_movement_report_run_id' => $movementRun->id,
                'incoming_stock_snapshot_at' => null,
                'incoming_stock_input_hash' => null,
                'sheets_published_at' => null,
                'sheet_publish_status' => null,
                'sheet_publish_error' => null,
            ])->save();
        }

        RunProcurementPipelineJob::dispatch($run->id)
            ->onQueue((string) config('procurement.queue', 'procurement'));

        return ['run' => $run, 'queued' => true];
    }
}
