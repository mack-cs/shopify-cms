<?php

namespace App\Jobs;

use App\Services\Inventory\DirectShopifyInventoryCorrectionReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReportDirectShopifyInventoryCorrectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function handle(DirectShopifyInventoryCorrectionReport $report): void
    {
        $report->send();
    }
}
