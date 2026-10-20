<?php

namespace App\Jobs;

use App\Exceptions\ShopYourVibeBusyException;
use App\Models\ShopYourVibeDraft;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PushShopYourVibe implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 840;

    public function __construct(public readonly int $draftId)
    {
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        try {
            $workflow->push($this->draftId);
        } catch (ShopYourVibeBusyException) {
            $this->release(15);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $draft = ShopYourVibeDraft::find($this->draftId);
        if (! $draft || $draft->status !== 'pushing') {
            return;
        }
        if (Cache::has('shop-your-vibe-op:'.$draft->collection_gid)) {
            return;
        }

        $draft->update([
            'status' => 'failed',
            'pending' => true,
            'last_error' => 'Push interrupted. Some changes remain pending. Retry to confirm completed Shopify operations.',
            'revision' => $draft->revision + 1,
        ]);
    }
}
