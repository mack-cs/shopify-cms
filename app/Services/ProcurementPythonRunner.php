<?php

namespace App\Services;

use App\Models\ProcurementPredictionRun;
use App\Models\ProductMovementReportRun;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ProcurementPythonRunner
{
    public function run(ProcurementPredictionRun $run, ProductMovementReportRun $movementRun): void
    {
        $pipelinePath = rtrim((string) config('procurement.pipeline_path'), '\\/');
        $script = $pipelinePath . DIRECTORY_SEPARATOR . 'run_pipeline.py';
        $python = trim((string) config('procurement.python_executable', 'python'));
        $baseUrl = rtrim((string) config('app.url'), '/');
        $token = trim((string) config('shopify_sync.analytics_export_token'));
        $timeout = (int) config('procurement.process_timeout_seconds', 7200);

        if ($python === '' || !is_file($script)) {
            throw new \RuntimeException("Procurement Python pipeline was not found at {$script}.");
        }
        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('APP_URL and SHOPIFY_ANALYTICS_EXPORT_TOKEN are required for procurement integration.');
        }
        if ($timeout < 60) {
            throw new \RuntimeException('PROCUREMENT_PROCESS_TIMEOUT_SECONDS must be at least 60.');
        }

        $process = new Process([$python, $script], $pipelinePath, [
            'LEIGH_CMS_ANALYTICS_BASE_URL' => $baseUrl,
            'LEIGH_CMS_ANALYTICS_TOKEN' => $token,
            'LEIGH_CMS_ANALYTICS_TO' => $run->calculation_date->toDateString(),
            'PROCUREMENT_PRODUCT_MOVEMENT_RUN_ID' => (string) $movementRun->id,
            'PROCUREMENT_RUN_UUID' => (string) $run->run_uuid,
            'PROCUREMENT_DEFAULT_LEAD_TIME_DAYS' => (string) $run->default_lead_time_days,
            'PROCUREMENT_ATTENTION_HORIZON_DAYS' => (string) $run->attention_horizon_days,
            'PROCUREMENT_PUBLISH_TO_CMS' => 'true',
        ]);
        $process->setTimeout($timeout);

        Log::info('Procurement prediction started', [
            'run_id' => $run->id,
            'movement_run_id' => $movementRun->id,
        ]);
        $process->mustRun();
        Log::info('Procurement prediction Python process completed', [
            'run_id' => $run->id,
            'output' => trim($process->getOutput()),
        ]);
    }
}
