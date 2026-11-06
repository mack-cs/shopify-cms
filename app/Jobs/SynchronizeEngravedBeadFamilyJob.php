<?php

namespace App\Jobs;

use App\Services\EngravedBeads\EngravedBeadSellability;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SynchronizeEngravedBeadFamilyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 8;

    public int $uniqueFor = 600;

    public array $backoff = [15, 30, 60, 120, 300];

    public function __construct(public int $familyId, public int $configurationVersion)
    {
        $this->onConnection('inventory-ledger')->onQueue('inventory-ledger');
    }

    public function uniqueId(): string
    {
        return 'bead-family:'.$this->familyId.':'.$this->configurationVersion;
    }

    public function handle(EngravedBeadSellability $service): void
    {
        $service->synchronize($this->familyId, $this->configurationVersion);
    }
}
