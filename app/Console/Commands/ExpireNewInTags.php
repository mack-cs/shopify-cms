<?php

namespace App\Console\Commands;

use App\Jobs\ExpireNewInTagsJob;
use App\Services\NewInExpirationLock;
use App\Services\NewInTagExpirationService;
use Illuminate\Console\Command;

final class ExpireNewInTags extends Command
{
    protected $signature = 'products:expire-new-in-tags
        {--dry-run : Report eligible products without modifying Shopify}
        {--limit= : Maximum number of eligible products to process}
        {--product= : Limit to a single Shopify product GID}
        {--queue : Queue the work instead of running inline}
        {--recover-local : Retry unfinished durable New In expiration operations}
        {--force : Allow live Shopify mutations without an interactive confirmation}';

    protected $description = 'Expire New In tag variations on products at least 75 days old (Shopify createdAt).';

    public function handle(NewInTagExpirationService $service, NewInExpirationLock $locks): int
    {
        if ($this->option('recover-local')) {
            if ($this->option('dry-run') || $this->option('queue') || $this->option('product') || $this->option('limit')) {
                $this->error('--recover-local cannot be combined with --dry-run, --queue, --product or --limit.');

                return self::FAILURE;
            }

            if (! (bool) config('new_in_expiration.enforcement_enabled', false)
                && ! (bool) config('new_in_expiration.recovery_enabled', false)
                && ! $this->option('force')) {
                $this->error('Recovery requires NEW_IN_EXPIRATION_ENFORCEMENT_ENABLED or NEW_IN_EXPIRATION_RECOVERY_ENABLED (or --force).');

                return self::FAILURE;
            }

            try {
                $lock = $locks->acquireExecution();
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            try {
                $locks->assertOwned($lock, 'New In recovery');
                $result = $service->recoverPendingLocalSyncs($lock);
                $locks->assertOwned($lock, 'New In recovery');
                $this->info(
                    "Local operation recovery attempted={$result['attempted']} synced={$result['synced']} failed={$result['failed']} needs_review={$result['needsReview']}"
                );

                return ($result['failed'] > 0 || $result['needsReview'] > 0) ? self::FAILURE : self::SUCCESS;
            } finally {
                $locks->release($lock);
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit');
        $limit = $limit === null || $limit === '' ? null : max(1, (int) $limit);
        $product = $this->option('product');
        $product = $product === null || trim((string) $product) === '' ? null : trim((string) $product);

        if (! $dryRun && ! (bool) config('new_in_expiration.enforcement_enabled', false) && ! $this->option('force')) {
            $this->error('Live expiration mutations require NEW_IN_EXPIRATION_ENFORCEMENT_ENABLED=true (or --force for controlled cleanup).');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            dispatch(
                (new ExpireNewInTagsJob($dryRun, $limit, $product, (bool) $this->option('force')))
                    ->onConnection((string) config('new_in_expiration.queue_connection', 'database'))
                    ->onQueue((string) config('new_in_expiration.queue', 'default'))
            );
            $this->info($dryRun ? 'Queued New In expiration dry-run.' : 'Queued New In expiration job.');

            return self::SUCCESS;
        }

        try {
            $lock = $locks->acquireExecution();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $locks->assertOwned($lock, 'New In expiration');

            if (! $dryRun && ! $this->option('force')) {
                $this->warn('Live Shopify tagsRemove mutations are enabled for this run.');
                if (! $this->confirm('Continue expiring New In tags against Shopify?', false)) {
                    $this->info('Aborted.');

                    return self::SUCCESS;
                }
            }

            $summary = $service->run(
                dryRun: $dryRun,
                limit: $limit,
                productGid: $product,
                mutate: ! $dryRun,
                executionLock: $lock,
            );
            $locks->assertOwned($lock, 'New In expiration');

            $this->info(($summary['dry_run'] ? 'Dry-run' : 'Executed').' New In tag expiration.');
            $this->line('Cutoff (UTC): '.$summary['cutoff']);
            $this->line('Discovered auto-remove tags: '.implode(', ', $summary['discovered_auto_remove_tags'] ?: ['(none)']));
            if ($summary['discovered_ambiguous_tags'] !== []) {
                $this->warn('Ambiguous tags (report-only): '.implode(', ', $summary['discovered_ambiguous_tags']));
            }
            $this->line("Assessed: {$summary['assessed']}");
            $this->line("Eligible: {$summary['eligible']}");
            $this->line("Skipped: {$summary['skipped']}");
            $this->line("Removed: {$summary['removed']}");
            $this->line("Failed: {$summary['failed']}");
            $this->line("Local sync failed: {$summary['local_sync_failed']}");

            foreach (array_slice($summary['products'], 0, 25) as $row) {
                $this->line(sprintf(
                    '- %s | %s | created=%s | age=%s | remove=[%s] | %s',
                    $row['shopify_product_id'] ?? '',
                    $row['title'] ?? '',
                    $row['created_at'] ?? 'n/a',
                    $row['age_days'] ?? 'n/a',
                    implode(', ', $row['tags_to_remove'] ?? []),
                    $row['action'] ?? ''
                ));
            }
            if (count($summary['products']) > 25) {
                $this->line('... '.(count($summary['products']) - 25).' more row(s)');
            }

            if ($summary['failed'] > 0 || $summary['local_sync_failed'] > 0) {
                return self::FAILURE;
            }

            return self::SUCCESS;
        } finally {
            $locks->release($lock);
        }
    }
}
