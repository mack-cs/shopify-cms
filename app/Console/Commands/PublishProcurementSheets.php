<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class PublishProcurementSheets extends Command
{
    protected $signature = 'procurement:sheets-publish';

    protected $description = 'Publish Laravel procurement state to the Master and brand tabs';

    public function handle(ProcurementSheetSyncService $sheets): int
    {
        $lock = Cache::lock('procurement-cycle', (int) config('google_sheets.lock_seconds', 14400));
        if (! $lock->get()) {
            $this->error('Another procurement cycle is already running.');

            return self::FAILURE;
        }
        try {
            $stats = $sheets->publish();
            $this->info("Published {$stats['master_rows']} Master and {$stats['brand_rows']} brand row(s).");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
