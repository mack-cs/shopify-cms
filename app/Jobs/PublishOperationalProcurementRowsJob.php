<?php

namespace App\Jobs;

use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishOperationalProcurementRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    /** @param array<int, int> $variantIds */
    public function __construct(public array $variantIds, public bool $includeHumanInputs = false) {}

    public function handle(ProcurementSheetSyncService $sheets): void
    {
        $sheets->publishOperational($this->variantIds, $this->includeHumanInputs);
    }
}
