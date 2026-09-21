<?php

namespace App\Jobs;

use App\Models\ManualStockTransaction;
use App\Services\ManualStockTransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessManualStockTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 300;
    public function __construct(public int $transactionId, public ?int $userId = null) {}
    public function handle(ManualStockTransactionService $service): void
    {
        $service->process(ManualStockTransaction::query()->findOrFail($this->transactionId), $this->userId);
    }
}
