<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryAuditBackfill;
use Illuminate\Console\Command;

class BackfillInventoryOperationAudits extends Command
{
    protected $signature = 'inventory:backfill-operation-audits {--dry-run : Count the rows that would be copied without writing them}';

    protected $description = 'Copy existing receipts, stack movements, manual stock, and applied corrections into the inventory audit log';

    public function handle(InventoryAuditBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = $backfill->run($dryRun);
        $verb = $dryRun ? 'Would copy' : 'Copied';
        $this->info($verb.' '.$counts['inserted'].' inventory operations. Skipped '.$counts['skipped'].' that were already logged, in progress, or missing a stable key.');
        $this->line('This command writes audit rows only. It does not call Shopify or change stock.');

        return self::SUCCESS;
    }
}
