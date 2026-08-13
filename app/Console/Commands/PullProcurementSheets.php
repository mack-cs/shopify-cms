<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class PullProcurementSheets extends Command
{
    protected $signature = 'procurement:sheets-pull';

    protected $description = 'Import human-owned incoming-stock phases from procurement brand tabs';

    public function handle(ProcurementSheetSyncService $sheets): int
    {
        $lock = Cache::lock('procurement-cycle', (int) config('google_sheets.lock_seconds', 14400));
        if (! $lock->get()) {
            $this->error('Another procurement cycle is already running.');

            return self::FAILURE;
        }
        try {
            $stats = $sheets->pullHumanInputs();
            $this->info("Read {$stats['rows']} row(s) from {$stats['tabs']} tab(s).");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
