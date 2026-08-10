<?php

namespace App\Console\Commands;

use App\Services\ProcurementPipelineService;
use Illuminate\Console\Command;

final class RunProcurementPipeline extends Command
{
    protected $signature = 'procurement:run {--date= : Calculation date in YYYY-MM-DD; defaults to today in South Africa}';
    protected $description = 'Queue the daily Product Movement and Python procurement prediction pipeline';

    public function handle(ProcurementPipelineService $pipeline): int
    {
        $timezone = (string) config('procurement.timezone', 'Africa/Johannesburg');
        $date = trim((string) $this->option('date')) ?: now($timezone)->toDateString();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date must be formatted as YYYY-MM-DD.');
            return self::FAILURE;
        }

        $result = $pipeline->queue($date);
        $run = $result['run'];
        $this->info($result['queued']
            ? "Queued procurement run #{$run->id} for {$date}."
            : "Procurement run #{$run->id} already has status {$run->status} for {$date}.");

        return self::SUCCESS;
    }
}
