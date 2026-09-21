<?php

namespace App\Jobs;

use App\Models\ShopifyStackOrderEvent;
use App\Services\Shopify\StackOrderReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessShopifyStackOrderEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public array $backoff = [15, 30, 60, 120, 300, 600, 1200];

    public function __construct(public readonly int $eventId) {}

    public function uniqueId(): string
    {
        return 'shopify-stack-order-event:'.$this->eventId;
    }

    public function handle(StackOrderReservationService $service): void
    {
        $service->process(ShopifyStackOrderEvent::query()->findOrFail($this->eventId));
    }
}
