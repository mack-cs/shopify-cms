<?php

namespace App\Jobs;

use App\Services\StackImageSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SynchronizeAuthorizedStackImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 5;

    public function __construct(public int $stackId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('authorized-stack-sync-'.$this->stackId))->expireAfter(600)->releaseAfter(15)];
    }

    public function handle(StackImageSync $sync): void
    {
        $sync->synchronizeStack($this->stackId);
    }

    public function failed(\Throwable $exception): void
    {
        app(StackImageSync::class)->abandonRunningCursor($this->stackId);
    }
}
