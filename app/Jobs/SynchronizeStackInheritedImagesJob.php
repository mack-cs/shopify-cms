<?php

namespace App\Jobs;

use App\Services\ComponentStackPropagation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SynchronizeStackInheritedImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 5;

    public function __construct(public int $taskId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 30, 60, 120, 300];
    }

    public function handle(ComponentStackPropagation $propagation): void
    {
        $propagation->synchronizeTask($this->taskId);
    }

    public function failed(\Throwable $exception): void
    {
        \Illuminate\Support\Facades\DB::table('stack_image_sync_tasks')
            ->where('id', $this->taskId)
            ->whereNotIn('phase', ['verified', 'superseded'])
            ->update([
                'phase' => 'exhausted',
                'last_error' => 'Retries exhausted: '.$exception->getMessage(),
                'updated_at' => now(),
            ]);
    }
}
