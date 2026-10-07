<?php

namespace App\Console\Commands;

use App\Services\Procurement\ProcurementPriceUpdateService;
use Illuminate\Console\Command;

final class ApplyProcurementPriceCostUpdates extends Command
{
    protected $signature = 'procurement:apply-price-cost-updates {--queue : Queue the work instead of applying it in this process}';

    protected $description = 'Apply populated New Price and New Cost values from the procurement Google Sheet.';

    public function handle(ProcurementPriceUpdateService $updates): int
    {
        $result = $this->option('queue')
            ? $updates->queueFromSheet()
            : $updates->applyFromSheetNow();

        $action = $this->option('queue') ? 'Queued' : 'Applied';
        $count = (int) ($result[$this->option('queue') ? 'queued' : 'applied'] ?? 0);

        $this->info("{$action} {$count} price/cost update row(s).");
        $this->line("Skipped {$result['skipped_unchanged']} unchanged row(s).");

        if (($result['skipped_unready'] ?? 0) > 0) {
            $this->warn("Skipped {$result['skipped_unready']} unready SKU(s): ".implode(', ', $result['unready_skus']));
        }

        return self::SUCCESS;
    }
}
