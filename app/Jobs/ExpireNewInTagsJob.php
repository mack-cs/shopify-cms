<?php

namespace App\Jobs;

use App\Services\NewInExpirationLock;
use App\Services\NewInTagExpirationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ExpireNewInTagsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        public readonly bool $dryRun = false,
        public readonly ?int $limit = null,
        public readonly ?string $productGid = null,
        public bool $force = false,
    ) {}

    public function handle(NewInTagExpirationService $service, NewInExpirationLock $locks): void
    {
        if (! $this->dryRun && ! ($this->force ?? false) && ! config('new_in_expiration.enforcement_enabled')) {
            return;
        }

        $lock = $locks->tryAcquireExecution();
        if ($lock === null) {
            $this->release(120);

            return;
        }

        try {
            $summary = $service->run(
                dryRun: $this->dryRun,
                limit: $this->limit,
                productGid: $this->productGid,
                mutate: ! $this->dryRun,
                executionLock: $lock,
            );
        } finally {
            $locks->release($lock);
        }

        Log::info('New In tag expiration job finished', [
            'dry_run' => $summary['dry_run'],
            'assessed' => $summary['assessed'],
            'eligible' => $summary['eligible'],
            'removed' => $summary['removed'],
            'failed' => $summary['failed'],
            'local_sync_failed' => $summary['local_sync_failed'],
            'auto_remove_tags' => $summary['discovered_auto_remove_tags'],
            'ambiguous_tags' => $summary['discovered_ambiguous_tags'],
        ]);

        if ($summary['failed'] > 0 || $summary['local_sync_failed'] > 0) {
            throw new \RuntimeException(
                "New In expiration completed with {$summary['failed']} Shopify failure(s) and {$summary['local_sync_failed']} local sync failure(s)."
            );
        }
    }
}
