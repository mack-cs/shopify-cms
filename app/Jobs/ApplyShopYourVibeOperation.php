<?php

namespace App\Jobs;

use App\Exceptions\ShopYourVibeBusyException;
use App\Models\ShopYourVibeDraft;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyShopYourVibeOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 840;

    public function __construct(public readonly int $draftId, public readonly string $attempt)
    {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        try {
            $workflow->runImmediate($this->draftId, $this->attempt);
        } catch (ShopYourVibeBusyException) {
            $this->release(15);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $draft = ShopYourVibeDraft::find($this->draftId);
        if (! $draft || ! in_array($draft->status, ['creating', 'deleting'], true)) {
            return;
        }
        if (($draft->operation['attempt'] ?? null) !== $this->attempt) {
            return;
        }
        if (cache()->has('shop-your-vibe-op:'.$draft->collection_gid)) {
            return;
        }

        $draft->update([
            'status' => 'failed',
            'pending' => false,
            'last_error' => $exception?->getMessage() ?: 'The Shopify operation was interrupted. Retry to continue from the last completed step.',
            'revision' => $draft->revision + 1,
        ]);
    }
}
