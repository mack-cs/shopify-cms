<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Console\Command;

class PublishOperationalProcurementSheets extends Command
{
    protected $signature = 'procurement:sheets-operational {--variant=* : Optional local variant IDs}';
    protected $description = 'Publish current inventory and CMS supplier-order fields without rerunning ML';

    public function handle(ProcurementSheetSyncService $sheets): int
    {
        $ids = collect($this->option('variant'))->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $result = $sheets->publishOperational($ids);
        $this->info("Published {$result['rows']} operational row update(s) across {$result['tabs']} tab(s).");
        return self::SUCCESS;
    }
}
