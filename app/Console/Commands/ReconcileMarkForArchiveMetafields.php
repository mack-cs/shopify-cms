<?php

namespace App\Console\Commands;

use App\Services\Procurement\ProcurementArchiveFlagSyncService;
use Illuminate\Console\Command;

final class ReconcileMarkForArchiveMetafields extends Command
{
    protected $signature = 'procurement:reconcile-mark-for-archive
        {--dry-run : Preview mismatches without writing to Shopify}
        {--force : Rewrite even when CMS already records a matching synced value}
        {--queue-retries : Queue pending/failed retries instead of reconciling from Ignore rows}
        {--limit= : Maximum number of products to examine}
        {--product= : Limit to a single CMS product ID}';

    protected $description = 'Reconcile procurement Ignore values to Shopify custom.mark_for_archive metafields.';

    public function handle(ProcurementArchiveFlagSyncService $sync): int
    {
        $limit = $this->option('limit');
        $limit = $limit === null || $limit === '' ? null : max(1, (int) $limit);
        $productId = $this->option('product');
        $productId = $productId === null || $productId === '' ? null : (int) $productId;
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('queue-retries')) {
            $result = $sync->retryPending(queue: true, limit: $limit);
            $this->info("Queued {$result['queued']} pending/failed mark-for-archive sync job(s).");

            return self::SUCCESS;
        }

        if (! $dryRun) {
            $this->warn('Live Shopify metafield writes are enabled for this run.');
            if (! $this->confirm('Continue reconciling mark_for_archive against Shopify?', false)) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $summary = $sync->reconcile(
            dryRun: $dryRun,
            limit: $limit,
            productId: $productId,
            force: (bool) $this->option('force'),
        );

        $mode = $dryRun ? 'Dry-run' : 'Executed';
        $this->info("{$mode} mark-for-archive reconciliation.");
        $this->line("Examined: {$summary['examined']}");
        $this->line("Would update: {$summary['would_update']}");
        $this->line("Updated: {$summary['updated']}");
        $this->line("Skipped: {$summary['skipped']}");
        $this->line("Unmapped: {$summary['unmapped']}");
        $this->line("Failed: {$summary['failed']}");

        if ($summary['conflicts'] !== []) {
            $this->warn('Products with conflicting variant Ignore values (OR policy applied):');
            foreach ($summary['conflicts'] as $conflict) {
                $this->line("- product {$conflict['product_id']} ({$conflict['sku']}): {$conflict['message']}");
            }
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
