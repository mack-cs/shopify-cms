<?php

namespace App\Jobs;

use App\Exceptions\ShopYourVibeBusyException;
use App\Models\ShopYourVibeDraft;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplyShopYourVibeOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 840;

    public readonly ?array $authorization;

    public function __construct(public readonly int $draftId, public readonly string $attempt)
    {
        $this->authorization = ShopYourVibeDraft::find($draftId)?->editing_state['push'] ?? null;
        $this->onConnection('shop-your-vibe');
        $this->onQueue('shop-your-vibe');
    }

    public function handle(ShopYourVibeWorkflow $workflow): void
    {
        try {
            if (! isset($this->authorization)) {
                throw new \RuntimeException('This legacy operation has no reviewed authorization. Reload and review before retrying.');
            }
            $workflow->runImmediate($this->draftId, $this->attempt, $this->authorization);
        } catch (ShopYourVibeBusyException) {
            $this->release(15);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $draft = ShopYourVibeDraft::lockForUpdate()->find($this->draftId);
            if (! $draft || ! in_array($draft->status, ['creating', 'deleting'], true)) {
                return;
            }
            if (($draft->operation['attempt'] ?? null) !== $this->attempt) {
                return;
            }
            if (! empty($draft->editing_state)
                && (! isset($this->authorization)
                    || ($draft->editing_state['push']['id'] ?? null) !== ($this->authorization['id'] ?? null)
                    || $draft->revision !== ($this->authorization['revision'] ?? null))) {
                return;
            }
            if (cache()->has('shop-your-vibe-op:'.$draft->collection_gid)) {
                return;
            }

            $draft->update([
                'status' => 'failed',
                'pending' => (bool) $draft->pending,
                'last_error' => $exception?->getMessage() ?: 'The Shopify operation was interrupted. Retry to continue from the last completed step.',
                'revision' => $draft->revision + 1,
            ]);
        });
    }
}
