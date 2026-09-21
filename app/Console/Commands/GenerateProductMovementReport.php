<?php

namespace App\Console\Commands;

use App\Jobs\GenerateProductMovementReportJob;
use App\Models\ProductMovementReportRun;
use App\Services\ProductMovementReportService;
use Illuminate\Console\Command;

final class GenerateProductMovementReport extends Command
{
    protected $signature = 'product-movement:generate
        {--months=6 : Number of months included in the report}
        {--force : Queue another run even if this reporting period already exists}';

    protected $description = 'Queue the shared manager and detailed product movement report';

    public function handle(ProductMovementReportService $reports): int
    {
        $months = (int) $this->option('months');
        if ($months < 1 || $months > 120) {
            $this->error('--months must be between 1 and 120.');

            return self::FAILURE;
        }

        $timezone = (string) config('product_movement.timezone', 'Africa/Johannesburg');
        $end = now($timezone)->startOfDay();
        $start = $end->copy()->subMonthsNoOverflow($months)->addDay();

        $existing = ProductMovementReportRun::query()
            ->whereDate('analysis_start_date', $start->toDateString())
            ->whereDate('analysis_end_date', $end->toDateString())
            ->whereIn('status', [
                ProductMovementReportRun::STATUS_QUEUED,
                ProductMovementReportRun::STATUS_RUNNING,
                ProductMovementReportRun::STATUS_COMPLETED,
            ])
            ->latest('id')
            ->first();

        if ($existing instanceof ProductMovementReportRun && !$this->option('force')) {
            $this->info("Product movement report run #{$existing->id} already exists for {$start->toDateString()} to {$end->toDateString()}.");

            return self::SUCCESS;
        }

        $run = $reports->createRun(
            $start->toDateString(),
            $end->toDateString(),
        );

        GenerateProductMovementReportJob::dispatch($run->id);

        $this->info("Queued shared manager and detailed movement report run #{$run->id} for {$start->toDateString()} to {$end->toDateString()}.");

        return self::SUCCESS;
    }
}
