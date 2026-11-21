<?php

namespace App\Jobs;

use App\Services\StackImageSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RebuildDependentStackImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 5;

    public function __construct(public int $componentProductId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('component-stack-rebuild-'.$this->componentProductId))->expireAfter(600)];
    }

    public function handle(StackImageSync $sync): void
    {
        if (! $sync->localRebuildEnabled()) {
            return;
        }
        $sync->rebuildDependents($this->componentProductId);
    }
}
