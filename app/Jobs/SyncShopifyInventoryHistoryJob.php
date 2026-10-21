<?php

namespace App\Jobs;

use App\Services\Inventory\ShopifyInventoryHistorySync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SyncShopifyInventoryHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 3;

    public function __construct(
        public string $day,
        public ?string $until = null,
        public ?string $app = null,
    ) {}

    public function handle(ShopifyInventoryHistorySync $sync): void
    {
        $sync->syncDay(Carbon::parse($this->day), $this->app);
        if ($this->until === null || $this->until === '') {
            return;
        }
        $next = Carbon::parse($this->day)->addDay()->startOfDay();
        $until = Carbon::parse($this->until)->startOfDay();
        if ($next->lte($until)) {
            self::dispatch($next->toDateString(), $until->toDateString(), $this->app)
                ->delay(now()->addSeconds(5));
        }
    }
}
