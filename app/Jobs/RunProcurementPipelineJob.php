<?php

namespace App\Jobs;

use App\Models\ProcurementPredictionRun;
use App\Models\ProductMovementReportRun;
use App\Services\ProcurementPythonRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RunProcurementPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400;
    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
    }

    public function handle(ProcurementPythonRunner $runner): void
    {
        $run = ProcurementPredictionRun::query()->find($this->runId);
        if (!$run instanceof ProcurementPredictionRun || $run->status === ProcurementPredictionRun::STATUS_COMPLETED) {
            return;
        }

        $run->forceFill([
            'status' => ProcurementPredictionRun::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ])->save();

        try {
            $movementRun = $run->movementRun;
            if (!$movementRun instanceof ProductMovementReportRun) {
                throw new \RuntimeException('The procurement run has no Product Movement snapshot.');
            }

            if ($movementRun->status !== ProductMovementReportRun::STATUS_COMPLETED) {
                GenerateProductMovementReportJob::dispatchSync($movementRun->id);
                $movementRun->refresh();
            }
            if ($movementRun->status !== ProductMovementReportRun::STATUS_COMPLETED) {
                throw new \RuntimeException('Product Movement snapshot did not complete successfully.');
            }

            $runner->run($run, $movementRun);
            $run->refresh();
            if ($run->status !== ProcurementPredictionRun::STATUS_COMPLETED) {
                throw new \RuntimeException('Python completed without publishing a complete prediction run to the CMS.');
            }

            Log::info('Procurement prediction completed', [
                'run_id' => $run->id,
                'prediction_rows' => $run->total_prediction_rows,
                'duration_seconds' => $run->started_at?->diffInSeconds($run->completed_at),
            ]);
        } catch (Throwable $exception) {
            $errorMessage = $this->summarizeError($exception);
            $run->refresh();
            if ($run->status !== ProcurementPredictionRun::STATUS_COMPLETED) {
                $run->forceFill([
                    'status' => ProcurementPredictionRun::STATUS_FAILED,
                    'completed_at' => now(),
                    'error_count' => max(1, (int) $run->error_count),
                    'error_message' => $errorMessage,
                ])->save();
            }
            Log::error('Procurement prediction failed', [
                'run_id' => $run->id,
                'error' => $errorMessage,
            ]);
            throw $exception;
        }
    }

    private function summarizeError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if (mb_strlen($message) <= 12000) {
            return $message;
        }

        return class_basename($exception)
            . ": output truncated; the final error was:\n"
            . mb_substr($message, -11500);
    }
}
