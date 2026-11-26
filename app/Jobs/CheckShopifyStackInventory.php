<?php

namespace App\Jobs;

use App\Models\NewProductDraft;
use App\Services\StackInventoryAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CheckShopifyStackInventory implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public const RESULT_KEY = 'stack-inventory:shopify-check';
    public const PENDING_KEY = 'stack-inventory:shopify-check-pending';
    public const ERROR_KEY = 'stack-inventory:shopify-check-error';

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onConnection('inventory-reconciliation')->onQueue('inventory-reconciliation');
    }

    public function handle(StackInventoryAuditService $audit): void
    {
        $rows = [];
        foreach (NewProductDraft::query()->whereNotNull('bundle_product_ids')->lazyById(100) as $stack) {
            $rows[$stack->id] = $audit->checkShopify($stack);
        }

        Cache::put(self::RESULT_KEY, ['checked_at' => now()->toIso8601String(), 'rows' => $rows], now()->addDay());
        Cache::forget(self::PENDING_KEY);
    }

    public function failed(Throwable $exception): void
    {
        Cache::put(self::ERROR_KEY, true, now()->addDay());
        Cache::forget(self::PENDING_KEY);
    }
}
