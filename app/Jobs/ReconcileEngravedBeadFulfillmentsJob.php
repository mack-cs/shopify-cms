<?php

namespace App\Jobs;

use App\Services\EngravedBeads\EngravedBeadFulfillmentReconciliation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class ReconcileEngravedBeadFulfillmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public array $backoff = [30, 60, 120, 300];

    public function __construct(public string $orderId)
    {
        $this->onConnection('inventory-ledger')->onQueue('inventory-ledger');
    }

    public function uniqueId(): string
    {
        return 'bead-fulfillments:'.$this->orderId;
    }

    public function handle(EngravedBeadFulfillmentReconciliation $service): void
    {
        $service->reconcile($this->orderId);
    }
}
