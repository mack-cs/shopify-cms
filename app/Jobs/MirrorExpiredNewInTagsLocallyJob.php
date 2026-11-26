<?php

namespace App\Jobs;

use App\Models\NewInExpirationOperation;
use App\Services\NewInExpirationLock;
use App\Services\NewInTagExpirationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class MirrorExpiredNewInTagsLocallyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function __construct(
        public readonly string $operationId,
    ) {}

    public function handle(NewInTagExpirationService $service, NewInExpirationLock $locks): void
    {
        if (! config('new_in_expiration.enforcement_enabled')) {
            return;
        }

        $lock = $locks->tryAcquireExecution();
        if ($lock === null) {
            $this->release(120);

            return;
        }

        try {
            $operation = NewInExpirationOperation::query()->find($this->operationId);
            if ($operation === null || $operation->status === NewInExpirationOperation::STATUS_CONFIRMED) {
                return;
            }

            $service->recoverOperation($operation, $lock);
        } finally {
            $locks->release($lock);
        }
    }
}
