<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class SyncProcurementSheets extends Command
{
    protected $signature = 'procurement:sheets-sync';

    protected $description = 'Import human phases then publish current procurement state';

    public function handle(ProcurementSheetSyncService $sheets): int
    {
        $lock = Cache::lock('procurement-cycle', (int) config('google_sheets.lock_seconds', 14400));
        if (! $lock->get()) {
            $this->error('Another procurement cycle is already running.');

            return self::FAILURE;
        }
        try {
            $pull = $sheets->pullHumanInputs();
            $publish = $sheets->publish();
            $this->info("Read {$pull['rows']} and published {$publish['master_rows']} Master row(s).");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
