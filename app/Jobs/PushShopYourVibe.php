<?php

namespace App\Jobs;

use App\Exceptions\ShopYourVibeBusyException;
use App\Models\ShopYourVibeDraft;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PushShopYourVibe implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 840;

    public readonly ?array $authorization;

    public function __construct(public readonly int $draftId)
    {
        $this->authorization = ShopYourVibeDraft::find($draftId)?->editing_state['push'] ?? null;
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        try {
            if (! isset($this->authorization)) {
                throw new \RuntimeException('This legacy push has no reviewed authorization. Reload and review before queueing a new push.');
            }
            $workflow->push($this->draftId, $this->authorization);
        } catch (ShopYourVibeBusyException) {
            $this->release(15);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $draft = ShopYourVibeDraft::lockForUpdate()->find($this->draftId);
            if (! $draft || $draft->status !== 'pushing') {
                return;
            }
            if (! empty($draft->editing_state)
                && (! isset($this->authorization)
                    || ($draft->editing_state['push']['id'] ?? null) !== ($this->authorization['id'] ?? null)
                    || $draft->revision !== ($this->authorization['revision'] ?? null))) {
                return;
            }
            if (Cache::has('shop-your-vibe-op:'.$draft->collection_gid)) {
                return;
            }

            $draft->update([
                'status' => 'failed',
                'pending' => true,
                'last_error' => $exception?->getMessage() ?: 'Push interrupted. Some changes remain pending. Retry to confirm completed Shopify operations.',
                'revision' => $draft->revision + 1,
            ]);
        });
    }
}
