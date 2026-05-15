<?php

namespace App\Jobs;

use App\Services\ComplementaryProductMaintenanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DailyComplementaryProductCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function handle(ComplementaryProductMaintenanceService $service): void
    {
        $service->runDailyCheck();
    }
}
